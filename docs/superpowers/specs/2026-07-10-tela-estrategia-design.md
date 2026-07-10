# Tela de Estratégia — design

Data: 2026-07-10
Estado: aprovado, pronto para plano de implementação

## Problema

O backend gera estratégias editoriais com IA desde `bcc67e8`, mas nada no frontend
consome esse fluxo. O `POST /projects/{id}/strategies:generate` e o
`GET /ai-runs/{id}` não têm cliente. Uma estratégia nasce `draft` e não existe
caminho para deixar de ser rascunho.

Esta é a fatia que fecha o laço: gerar, ver, aprovar.

## Escopo

**Dentro.** Disparar a geração, acompanhar a execução assíncrona, renderizar a
estratégia (título, resumo, linha editorial, pilares com pesos), aprovar
(`status: active`) e descartar (`status: archived`). Tratar as três falhas
distintas com afordâncias distintas.

**Fora.** Editar título, resumo ou linha editorial. Histórico de estratégias.
Os outros seis agentes. Bloquear a geração quando o Perfil da Marca está
incompleto.

## Decisões

### A tela mostra só a estratégia mais recente

`GET /projects/{id}/strategies` devolve todas, mais recente primeiro. A tela usa
`data[0]` e ignora o resto. O histórico existe no banco e não aparece.

**Risco aceito:** o backend não impede duas estratégias `active` no mesmo
projeto. Aprovar duas vezes deixa duas ativas. Nada na tela depende desse
invariante, e mantê-lo exigiria uma transação no servidor (arquivar as outras ao
ativar uma) — uma fatia de backend que esta não paga. Registrado aqui para não
ser redescoberto como bug.

### O `ai_run_id` vive na URL, não no estado do React

Após o `202`, a tela faz `navigate('?run=42', { replace: true })`.

O motivo não é elegância. Se o id vivesse em `useState`, recarregar a página no
meio da geração perderia o acompanhamento. Uma execução que terminasse em
recusa nunca mostraria o porquê: o usuário voltaria ao estado vazio, clicaria em
"Gerar" de novo e levaria outra recusa, queimando orçamento. É o mesmo loop que
o fix de `bcc67e8` corrigiu no servidor — não faz sentido reintroduzi-lo no
cliente.

Consequência: **`succeeded` limpa o `?run`** (a estratégia passou a viver na
lista; a execução não interessa mais), **`failed` mantém** e para o polling. O
`?run` só sai por ação explícita: descartar a mensagem, tentar de novo, ou gerar
outra.

### O backend passa a expor `error_code`

Existem três falhas e elas pedem afordâncias opostas:

| Falha | Como chega | Retry ajuda? |
|---|---|---|
| Orçamento estourado | `402` no POST, sem criar execução | Não |
| Recusa do modelo | `ai_run.status = failed` | **Não** — mesmo prompt, mesma recusa |
| Falha transiente do provedor | `ai_run.status = failed` | **Sim** |

As duas últimas são indistinguíveis para o cliente: mesmo `status`, e a diferença
só existe na prosa de `error`. O `RunAgentJob` **sabe** qual foi (ele faz `match`
na classe da exceção em `userFacingMessage()`) e joga fora essa informação.

A alternativa — a UI comparar `run.error` com `'O modelo recusou'` — amarra
comportamento a uma frase em português. Corrigir um acento faria o botão de
tentar de novo reaparecer numa recusa, silenciosamente, sem nenhum teste pegar.

Então persistimos o que o job já sabe.

## Fatia 3b — `error_code` em `ai_runs` (backend)

**Migration.** `enum('error_code', ['refused', 'rejected_output', 'provider_failed'])
->nullable()`. Segue o padrão de `agent` e `status`, já `enum` na mesma tabela.
Sem `->after()`: é modificador exclusivo do MySQL, e o grammar do Postgres o
ignora. A coluna vai para o fim da tabela, o que não tem consequência. Fica `null` no sucesso — o código só é escrito no `catch`, então
`null` significa "não falhou", nunca "falhou por motivo desconhecido".

**Job.** Um método irmão do `userFacingMessage()`:

```php
private function errorCodeFor(Throwable $e): string
{
    return match (true) {
        $e instanceof LlmRefusedException => 'refused',
        $e instanceof OutputRejectedException => 'rejected_output',
        default => 'provider_failed',
    };
}
```

Os dois fazem `match` na mesma coisa. É duplicação aparente: um responde a um
humano, o outro a uma máquina, e eles vão divergir — a mensagem muda de redação,
o código nunca pode mudar.

**Controller.** `AiRunController::show()` acrescenta `'error_code'`.

**Contrato resultante:**
`{ id, agent, status, output, error, error_code, cost_cents, latency_ms, created_at }`

**Fora desta fatia:** `Strategy`, rotas, `Budget`, `AnthropicProvider`.

## Fatia 1b — a tela (frontend)

### Fronteiras

Um hook, `useStrategyGeneration(projectId)`, é o único lugar que sabe que existem
URL, intervalo de polling e mutação. Expõe um estado discriminado e três ações:

```ts
type GenerationState =
  | { kind: 'idle' }
  | { kind: 'starting' }                                    // POST em voo
  | { kind: 'running'; slow: boolean }                      // polling
  | { kind: 'failed'; message: string; retryable: boolean }
  | { kind: 'budget'; spentCents: number; limitCents: number }
  | { kind: 'lost' }                                        // ?run inexistente

{ state, generate(), retry(), dismiss() }
```

`retryable = error_code === 'provider_failed'`. A tradução acontece **num lugar
só**. Nenhum componente vê a string `'refused'`. Um quarto código no futuro tem
um único ponto de decisão.

Os componentes abaixo do hook não sabem que a rede existe. `GenerationStatus`
recebe `state` e callbacks. `StrategyCard` recebe uma estratégia e
`onApprove`/`onArchive`. `Pillars` recebe a lista. Todos são props → JSX,
testáveis sem MSW. `StrategyPage` compõe os três, faz a query da lista e possui
a mutação de aprovar.

### Fluxo de dados

Duas queries, passando pelo `api()` existente:

- `['strategies', projectId]` → `GET /projects/{id}/strategies`, usa `data[0]`.
- `['ai-run', runId]` → habilitada só quando há `?run`.

O polling se desliga sozinho:

```ts
refetchInterval: (query) => {
  const status = query.state.data?.status
  return status === 'succeeded' || status === 'failed' ? false : 1500
}
```

**Gotcha do TanStack v5:** `useQuery` não tem mais `onSuccess`. Invalidar a lista
e limpar o `?run` vai num `useEffect` que observa `status === 'succeeded'`. É a
única forma na v5; merece comentário no código.

**`slow` vem do servidor, não de um timer.** A execução traz `created_at`, e o
polling já roda a cada 1,5 s: `Date.now() - Date.parse(run.created_at) > 120_000`.
Sem `setInterval` próprio, e sobrevive a reload porque a origem do tempo está no
servidor. Passados dois minutos, a tela diz que está demorando — em vez de girar
para sempre quando a fila está parada.

### Precedência do estado

A ordem importa:

1. Mutação falhou com `ApiError` 402 → `budget` (`spent_cents`, `limit_cents`).
2. Há `?run` e a query dele deu 404 → `lost`. O `queryClient` em `main.tsx` já
   não repete 404.
3. `run.status === 'failed'` → `failed`, com `retryable` derivado do `error_code`.
4. `run.status` em `queued`/`running` → `running`.
5. Mutação em voo → `starting`.
6. Senão → `idle`.

O 402 vem primeiro porque acontece **sem criar execução**: não há `?run` para
consultar. É o único erro que a tela conhece pelo corpo da resposta, e não pela
tabela `ai_runs`.

### Ações

`retry()` remove o `?run` e dispara um POST novo. `dismiss()` só remove. Sem
limpar antes, um `?run` velho e falho conviveria com uma execução nova, e a
precedência acima mostraria o erro antigo.

Aprovar e descartar são `PATCH /strategies/{id}` com `{status: 'active'}` e
`{status: 'archived'}`, ambos invalidando a lista. Depois de descartar, a tela
continua mostrando aquela estratégia, agora com chip `Arquivada` e botão
`Gerar nova` — consistente com "só a mais recente", e evita um estado vazio que
mentiria dizendo que nada foi feito.

Quais ações aparecem depende do `status` da estratégia exibida:

| `status` | Chip | Ações |
|---|---|---|
| (nenhuma estratégia) | — | `Gerar estratégia` |
| `draft` | Rascunho | `Aprovar` · `Descartar` |
| `active` | Ativa | `Gerar nova` |
| `archived` | Arquivada | `Gerar nova` |

`Gerar nova` e `Gerar estratégia` são o mesmo `generate()`; o rótulo muda porque
o significado muda.

Enquanto uma nova geração corre, a tela **continua mostrando a estratégia atual**.
Nada de piscar para vazio.

**Mas o botão `Gerar nova` fica desabilitado durante a geração.** Isto não estava
no design original e custou caro: ao dirigir o browser contra o servidor real,
dois cliques rápidos criaram duas execuções, duas estratégias e cobraram duas
vezes do orçamento. O `Budget` só verifica **antes** de enfileirar; nada impede
duas gerações concorrentes no mesmo projeto. A defesa é o `disabled`, e o teste
que a segura clica duas vezes e exige um único POST.

Nenhum dos nove testes originais pegava isso — todos clicavam uma vez. Foi o
browser que encontrou.

### Duas regras que não são duplicadas no cliente

`Pillars` não confere que os pesos somam 100: quem garante é
`StrategistAgent::validate()`. E a tela não bloqueia a geração quando o Perfil da
Marca está incompleto: as instruções do agente já mandam propor uma estratégia
conservadora e dizer isso no resumo. Duplicar qualquer uma das duas criaria dois
donos para a mesma regra.

### Arquivos

Novos: `hooks/useStrategyGeneration.ts`, `pages/StrategyPage.tsx`,
`components/strategy/{StrategyCard,Pillars,GenerationStatus}.tsx`,
`components/ui/Shell.tsx`.

Tocados: `lib/types.ts` (`Strategy`, `Pillar`, `AiRun`, `AiRunErrorCode`),
`main.tsx` (rota `/projects/:projectId/strategy`), e as três páginas existentes
que passam a importar o `Shell` extraído.

**Limpeza adjacente aprovada:** o `Shell` está copiado em `ProjectsPage` e
`BrandProfilePage` (a `LoginPage` não usa). Esta tela seria a terceira cópia.
Extrair para `components/ui/Shell.tsx` e trocar as duas chamadas.

## Testes

### Backend (fatia 3b)

Em `StrategyGenerationTest`, quatro casos novos com provider fake no container,
mais uma asserção no teste existente:

- sucesso → `error_code` é `null`
- `LlmRefusedException` → `refused`
- provider devolve pilares somando 99 → job tenta duas vezes, desiste, grava
  `rejected_output`. De quebra, é a primeira cobertura do laço de retentativa de
  `generateAndValidate()`, hoje sem nenhuma.
- `LlmFailedException` → `provider_failed`
- o endpoint de polling expõe `error_code`

### Frontend (fatia 1b)

Infra: `vitest` + `jsdom` + Testing Library (`react`, `user-event`, `jest-dom`) +
`msw` v2. Um `vitest.config.ts`, um `src/test/setup.ts`, um `src/test/server.ts`.
Script `"test": "vitest run"`.

**Uma terceira armadilha, descoberta ao executar:** o `findBy*` desiste em
**1000 ms** por padrão, mas o `refetchInterval` é de **1500 ms**. O segundo poll
nunca acontecia, e o caminho feliz falhava com o `?run` ainda na URL e nenhuma
estratégia na tela — parecendo bug do hook, quando era a espera curta demais.
Resolvido com `configure({ asyncUtilTimeout: 5000 })` em `src/test/setup.ts`.

**Duas armadilhas conhecidas de antemão.** O TanStack Query precisa de um `QueryClient` novo
por teste, com `retry: false` — um cliente compartilhado vaza cache entre testes,
e o retry padrão transforma um 404 esperado em três segundos de espera. E o
polling com timers reais faria cada teste levar segundos: a combinação que
funciona é `vi.useFakeTimers({ shouldAdvanceTime: true })` com
`userEvent.setup({ advanceTimers: vi.advanceTimersByTime })`. Sem o
`shouldAdvanceTime`, o `findBy*` da Testing Library nunca resolve, porque ele
também depende de timer.

Oito casos em `StrategyPage.test.tsx`:

1. estado vazio oferece gerar
2. caminho feliz: clique → `running` → estratégia na tela, `?run` limpo da URL
3. **recusa mostra a mensagem e não renderiza botão de tentar de novo** — o teste
   que justifica a fatia do `error_code`; assere ausência, que ninguém escreve
   por acidente
4. falha transiente renderiza o botão, e clicá-lo dispara um POST novo
5. 402 mostra gasto e limite, e não inicia polling: a URL segue sem `?run` e o
   indicador de execução nunca aparece
6. montar com `?run=42` já na URL retoma o estado sem disparar POST — o teste do
   reload no meio da geração, o cenário que motivou pôr o id na URL
7. `?run` inexistente vira `lost`
8. aprovar troca o chip para `Ativa`
9. dois minutos em `running` (com `created_at` velho) mostram o aviso de demora —
   sem isso, `slow` seria um ramo sem cobertura

Mais um teste isolado em `Pillars`: três pilares, três nomes e pesos renderizados.

**O que não será testado:** `useStrategyGeneration` isoladamente. O interessante
nele é a fiação — URL, polling, precedência — e fiação se testa montando a
página. Um `renderHook` aqui repetiria a implementação em vez de descrever
comportamento.

### Verificação final

Depois da suíte verde: `pnpm dev` + `php artisan serve` + `php artisan queue:work`,
e percorrer o caminho feliz no browser uma vez. O `MockProvider` responde na hora,
então o estado `running` mal aparece — vale um `sleep(3)` temporário no provider
para ver o polling girar de verdade.

## Critério de sucesso

- `php artisan test` verde, incluindo os cinco casos de `error_code`.
- `pnpm test` verde, incluindo os nove casos da tela e o de `Pillars`.
- O caminho feliz percorrido no browser.
- Uma execução em `refused` não oferece botão de tentar de novo, e a mensagem
  sobrevive a um reload da página.
