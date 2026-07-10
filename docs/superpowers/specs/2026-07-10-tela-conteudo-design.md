# Tela de Conteúdo (biblioteca por status) — design

Data: 2026-07-10
Estado: aprovado, pronto para plano de implementação

## Problema

O `CopywriterAgent` grava 5 peças em `contents` a cada geração, mas **nenhuma UI
lê a tabela**. O valor fica preso no banco: o usuário dispara a geração (hoje só
por `curl`, porque `copy:generate` não tem botão em tela nenhuma) e nunca vê o
resultado. A peça nasce `status='idea'` e não há caminho para promovê-la — o
mesmo laço aberto que a estratégia tinha antes da tela de Estratégia (nascia
`draft` sem virar `active`).

Esta fatia dá à peça uma tela: ver, mover pelo fluxo, e gerar.

## Escopo

**Dentro.** Backend de leitura (`GET /projects/{id}/contents`) e transição de
status (`PATCH /contents/{id}`) com a regra de fluxo dona do servidor, gravando
`content_revisions`. Frontend: uma biblioteca agrupada por status, com botão de
gerar conteúdo (reaproveitando o polling), e mover peça ±1 passo / arquivar.

**Fora.** Editar o texto da peça (title/caption/cta/hashtags). `scheduled_for` e
calendário. Campanhas. `content_comments`. Os status `scheduled`/`published` (que
dependem de agendar e exportar, fatias futuras). Os outros 5 agentes.

## Decisões

### Biblioteca agrupada por status, não calendário

`contents` tem `scheduled_for`, o que sugere calendário. Mas as peças de IA
nascem com `scheduled_for` **nulo** — um calendário mostraria vazio sobre 5 peças
reais. A biblioteca agrupa por `status` (idea → production → review → approved),
e as peças de IA aparecem imediatamente na coluna "Ideia". O calendário é fatia
futura, depois de existir um jeito de agendar.

### Ver + mover status + arquivar (sem editar texto)

A peça mostra seu conteúdo e o usuário a move pelo fluxo ou arquiva. Espelha o
aprovar/descartar da estratégia. Editar texto fica para depois — é onde a
estratégia também parou.

### O fluxo é dono do servidor; avanço de ±1 passo

Dois botões, "Avançar" e "Voltar", movem a peça um passo na sequência
`idea → production → review → approved`. "Arquivar" leva a `archived` de qualquer
estado ativo. O backend valida a transição: pular etapa (`idea → published`) é
**422**. Isso trava três coisas de uma vez — não se pula etapa, não se avança
além de `approved`, e `scheduled`/`published` não são alcançáveis por esta tela.

`archived` é terminal (não se desarquiva por aqui).

### Para em `approved`; grava `content_revisions`

"Avançar" vai até `approved` e para (o botão desabilita). `scheduled`/`published`
exigem agendar e exportar, que não existem. Cada mudança de status grava uma
linha em `content_revisions` (from → to, quem, quando) **na mesma transação** do
PATCH. O histórico nasce junto — reconstruir depois é impossível (não dá para
saber quem moveu o quê no passado).

A revisão registra **movimentos de status**, não o nascimento da peça: a criação
já está rastreada por `source='ai'` + `origin_ai_run_id`. `from_status`/`to_status`
são `nullable` na migration, mas aqui sempre vêm preenchidos (a primeira revisão
aparece no primeiro movimento, com o `from` real).

### A tela dispara a geração, reaproveitando o hook

A biblioteca tem um botão "Gerar conteúdo" que chama `POST /copy:generate` e faz
polling em `/ai-runs/{id}` até as peças aparecerem. É o lugar natural: gerar e ver
no mesmo lugar. E é a única entrada de UI para `copy:generate`, que hoje só
existe por `curl`.

### O `useStrategyGeneration` vira `useGeneration` (generalizado)

O que muda entre gerar estratégia e gerar copy são dois valores: a URL do POST e
a query a invalidar no sucesso. Todo o resto — `?run` na URL, polling,
precedência de estado, retry/dismiss — é idêntico. Duplicar um hook de polling
com precedência de erro é o tipo de duplicação que apodrece (esta sessão achou o
bug do duplo clique e o do 409 ali dentro). Então generaliza-se:

```ts
useGeneration({ projectId, endpoint, invalidateKey })
```

O `useStrategyGeneration` **deixa de existir** — é um rename com parâmetros, não
uma camada nova. A `StrategyPage` passa a usar o hook generalizado; seus 11 testes
provam que a extração não regrediu.

## Backend (`apps/api`)

### Relação

`Project` ganha `contents(): HasMany`.

### `ContentController`

`index(Project)`: `Gate::authorize('view')`, devolve
`$project->contents()->latest()->get()` como `{data}`. Lista plana; a tela agrupa
no cliente.

`update(Request, Content)`: `Gate::authorize('update', $content->project)`. Aceita
só `status`. Valida a transição; grava na transação.

```php
private const FLOW = ['idea', 'production', 'review', 'approved'];

private function isValidTransition(string $from, string $to): bool
{
    if ($to === 'archived') {
        return $from !== 'archived';
    }

    $i = array_search($from, self::FLOW, true);
    $j = array_search($to, self::FLOW, true);

    return $i !== false && $j !== false && abs($i - $j) === 1;
}
```

Transição inválida → **422** `{message}`. Válida:

```php
DB::transaction(function () use ($content, $from, $to) {
    $content->update(['status' => $to]);
    ContentRevision::create([
        'content_id' => $content->id,
        'user_id' => request()->user()->id,
        'from_status' => $from,
        'to_status' => $to,
    ]);
});
```

### `ContentRevision` model

**Criar** — model magro, `$fillable` = `content_id`, `user_id`, `from_status`,
`to_status`. **`public $timestamps = false`** — confirmado na migration: a tabela
só tem `created_at` (com `useCurrent()`), sem `updated_at`. Sem o flag, o Eloquent
tentaria escrever `updated_at` e o insert falharia.

### Isolamento de tenant

Confirmado: `Content` já tem `#[ScopedBy(WorkspaceMemberScope::class)]`, igual ao
`Strategy`. O route-model-binding de `PATCH /contents/{content}` filtra por
workspace automaticamente — um intruso recebe **404** no binding, antes do
controller. É o mesmo mecanismo que protege `PATCH /strategies/{strategy}`.

### Rotas

```php
Route::get('projects/{project}/contents', [ContentController::class, 'index']);
Route::patch('contents/{content}', [ContentController::class, 'update']);
```

### Contrato

- `GET /projects/{id}/contents` → `{data: Content[]}`
- `PATCH /contents/{id}` `{status}` → **200** `{data}` ou **422** transição inválida

## Frontend (`apps/web`)

### `useGeneration` (hook generalizado)

Extraído de `useStrategyGeneration`. Corpo idêntico, parametrizado por
`{projectId, endpoint, invalidateKey}`. O `GenerationState`, `POLL_INTERVAL_MS`,
`SLOW_AFTER_MS` ficam no mesmo módulo.

O 422 do copy (sem estratégia ativa) entra no `deriveState` **antes** do 402 —
pré-condição mais específica, mesma lógica do 409. Não é retentável (o usuário
precisa aprovar uma estratégia, não insistir):

```ts
if (generationError instanceof ApiError && generationError.status === 422) {
  return { kind: 'failed', message: (generationError.body as MessageBody).message, retryable: false }
}
```

`StrategyPage` troca `useStrategyGeneration(projectId!)` por
`useGeneration({ projectId, endpoint: 'strategies:generate', invalidateKey: ['strategies', projectId] })`.
Nada mais na página muda — a assinatura de retorno é idêntica.

### `ContentPage`

Query `['contents', projectId]`; mutação `move` (`PATCH /contents/{id}` com
`{status}`, invalida a lista); `useGeneration` com `endpoint: 'copy:generate'`,
`invalidateKey: ['contents', projectId]`.

Agrupa por `groupByStatus(contents)` (função pura, testada isolada) nas cinco
colunas visíveis: `idea`, `production`, `review`, `approved`, `archived`.
`scheduled`/`published` não aparecem; o backend não permite alcançá-las por aqui.

### `ContentBoard`

Recebe os grupos, desenha as colunas: título com contagem (`Ideia (5)`) + pilha
de cards. Coluna vazia mostra `(0)` sem pilha — o board não some, o usuário vê o
fluxo inteiro. Props → JSX.

### `ContentCard`

Recebe uma peça e `onAdvance`/`onBack`/`onArchive`. Mostra `format · channel`,
título, caption (truncada), CTA, hashtags, chip de status, e chip "Gerado por IA"
quando `source='ai'` (ADR-11). Os botões acendem por status, derivado do mesmo
`FLOW` do backend — mas no cliente é só habilitar/desabilitar; o servidor valida:

- `Voltar` desabilitado em `idea`.
- `Avançar` desabilitado em `approved` e `archived`.
- `Arquivar` desabilitado em `archived`.

Se cliente e servidor discordarem (bug no FLOW do cliente), o pior caso é um botão
habilitado que o servidor rejeita com 422 — a mutação falha, a lista não muda.
Cliente sugere, servidor decide.

### Estados vazios

Projeto sem peça: botão "Gerar conteúdo" + "Ainda não há conteúdo. Gere a partir
da estratégia ativa."

### Rota e navegação

`/projects/:projectId/content` no `main.tsx`, sob `RequireAuth`. Link "Ver
conteúdo →" na `StrategyPage`, no lugar onde ela já linka para o Perfil da Marca.

### Arquivos

Novos: `pages/ContentPage.tsx`, `components/content/{ContentBoard,ContentCard}.tsx`,
`lib/groupByStatus.ts`. Tocados: `lib/types.ts` (`Content`, `ContentStatus`),
`main.tsx`, `pages/StrategyPage.tsx` (link + `useGeneration`),
`hooks/useStrategyGeneration.ts` → `hooks/useGeneration.ts` (rename).

## Testes

### Backend

`ContentController` (feature):
- `index` lista as peças do projeto; isolamento de tenant (projeto de outro
  workspace → 404)
- `update` de `idea` para `production` → 200, status muda, **grava 1 revisão**
  (from='idea', to='production', user)
- pular etapa (`idea` → `published`) → 422, status **não** muda, 0 revisões
- avançar além de `approved` (`approved` → `scheduled`) → 422
- voltar um passo (`review` → `production`) → 200
- arquivar de qualquer estado ativo → 200; arquivar de `archived` → 422
- viewer não pode mover (403); a transição válida gravada por editor
- a revisão e a mudança de status são atômicas: se a validação falha, nem status
  nem revisão mudam

### Frontend

`groupByStatus.test.ts` (puro): agrupa peças nas colunas certas; coluna sem peça
vira array vazio; status oculto (`published`) não aparece nas 5 colunas.

`ContentPage.test.tsx` (MSW):
- lista vazia → botão "Gerar conteúdo" e a frase de vazio
- peças agrupadas: 5 em "Ideia", colunas seguintes vazias com `(0)`
- avançar uma peça → PATCH com o próximo status, a peça migra de coluna
- o card de `source='ai'` mostra o chip "Gerado por IA"
- gerar: clique → polling → as peças aparecem (reusa o fluxo do `useGeneration`)
- 422 sem estratégia ativa → mensagem, sem polling (o mesmo padrão do 402)

`StrategyPage.test.tsx`: **os 11 testes existentes rodam sem alteração** e provam
que a troca para `useGeneration` não regrediu.

### Verificação final

`php artisan test` e `pnpm test` verdes. No browser: gerar conteúdo a partir da
estratégia ativa do projeto 1, ver as 5 peças em "Ideia", avançar uma até
"Aprovado", conferir que a revisão foi gravada e que pular etapa é barrado.

## Critério de sucesso

- Backend: `index` + `update` com a regra de transição, `content_revisions`
  gravado atomicamente, os testes de fluxo verdes.
- Frontend: biblioteca por status, gerar+polling reaproveitando `useGeneration`,
  os 11 testes da `StrategyPage` intactos.
- No browser: o loop copywriter → biblioteca → aprovar, fechado numa tela.
