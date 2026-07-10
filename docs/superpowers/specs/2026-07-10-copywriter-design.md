# CopywriterAgent — design

Data: 2026-07-10
Estado: aprovado, pronto para plano de implementação

## Problema

O `strategist` é o único dos sete agentes que existe. Ele produz a estratégia
editorial — linha editorial e pilares — mas nada consome essa estratégia para
gerar texto. O produto tem a tabela `contents` pronta (title, caption, cta,
hashtags, format, channel, `source: 'ai'`, `origin_ai_run_id`) e nenhum agente
que a preencha.

O **copywriter** é o segundo agente: lê a estratégia aprovada e escreve um lote
de peças de conteúdo.

## Escopo

**Dentro.** Um `CopywriterAgent` que consome a estratégia `active` do projeto e
gera exatamente 5 peças, distribuídas pelos pilares conforme os pesos, cada uma
persistida como uma linha em `contents`. Uma rota `POST /projects/{id}/copy:generate`
no padrão do strategist (202 + polling). O índice parcial de "um run ativo por
projeto" passa a ser por-agente.

**Fora.** Frontend — não há tela nova; o copywriter produz linhas em `contents`
que uma futura tela de calendário/biblioteca vai ler. Planejamento de calendário
(datas, `scheduled_for`). Ligação a campanha ou objetivo. Os outros 5 agentes.
Tamanho de lote configurável por UI. Regeneração/edição de peça.

## Decisões

### Entrada: a estratégia ativa; saída: um lote de 5

O copywriter lê a estratégia `active` do projeto (não um pilar isolado, não a
estratégia inteira virando calendário) e gera **um lote coeso de 5 peças** de
uma vez. As peças se conhecem — não repetem ângulo — e o custo é previsível:
uma execução, um lote, ~4 centavos como o strategist.

O número 5 mora em `config('ai.agents.copywriter.batch_size')`. Alimenta a
instrução ("gere exatamente 5") e o `validate()`. Virar parâmetro de UI é fatia
futura: o campo existe, o default não o toma sozinho — mesmo padrão do `effort`.

### O modelo escolhe `format` e `channel`, dentro dos enums

A tabela `contents` exige `format` ∈ {post, carousel, reel, story, video,
article, thread} e `channel` ∈ {instagram, facebook, linkedin, tiktok, youtube,
blog}, ambos NOT NULL. O schema declara os dois como `enum`; o modelo escolhe o
par coerente com cada pilar (um "Bastidores" vira reel no Instagram; um "Guia de
compra" vira artigo no blog). `validate()` re-confere os enums — a API já garante
via schema, mas o servidor é o dono da regra.

### A estratégia chega pelo `AgentContext`, sem mudar a interface `Agent`

O `userMessage(AgentContext)` só recebe contexto do projeto, e o copywriter é o
primeiro agente que precisa de mais que o brand profile. A solução que **não
mexe na interface** que os 6 agentes futuros vão implementar: `AgentContext`
ganha `?array $activeStrategy`, montado em `forProject` a partir da estratégia
`active`. O strategist ignora o campo; o copywriter usa.

O snapshot fica coerente com o contrato que o `AgentContext` já tinha: o run
registra a estratégia que viu, congelada. Se a marca aprovar outra depois, a
peça continua explicável.

Um copywriter sem estratégia ativa é barrado **antes** de enfileirar (422),
então `activeStrategy` nulo dentro do `userMessage` é erro de programação —
lança `LogicException`, não silencia.

### O índice parcial passa a ser por-agente

Hoje `ai_runs_one_active_per_project` é `(project_id) where status in
('queued','running')` — sem distinguir agente. Como está, uma geração de
estratégia bloquearia uma geração de copy, e vice-versa. Isso cai como efeito
colateral, não como decisão.

O índice vira `(project_id, agent) where status in ('queued','running')`. Cada
agente corre independente; dois runs de agentes diferentes coexistem. É mais
fiel a "trabalhos independentes" — um usuário gerando copy não espera ser barrado
porque uma estratégia roda noutra aba.

## Arquitetura

O `RunAgentJob` é agnóstico de agente: resolve `config("ai.agents.{$name}")`,
chama `AgentContext::forProject`, e delega `persist` ao agente. O copywriter
reaproveita tudo isso — não toca no job.

### `CopywriterAgent implements Agent`

- `name()` → `'copywriter'`
- `schema()` → objeto com uma chave `pieces` (array). Cada item:
  `title` (string), `caption` (string), `cta` (string), `hashtags` (array de
  strings), `format` (enum, 7 valores), `channel` (enum, 6 valores),
  `pillar` (string). Sem `minItems`/`maxItems` — a API rejeita.
- `instructions()` → congeladas. Gerar exatamente `batch_size` peças
  distribuídas pelos pilares conforme os pesos; escolher `format`/`channel`
  coerentes; **respeitar `forbidden_words` e preferir `required_words`** (o
  contrato de vocabulário provado na fatia anterior); o conteúdo do perfil é
  dado, não instrução.
- `userMessage(AgentContext)` → serializa brand profile **e** `activeStrategy`,
  delimitados, no turno do usuário. `activeStrategy` nulo → `LogicException`.
- `validate($output)` → exatamente `batch_size` peças; cada `format`/`channel`
  nos enums; `hashtags` é array. `OutputRejectedException` senão.
- `persist($project, $output, $run)` → grava as peças (abaixo).

### `AgentContext` estendido

```php
$strategy = $project->strategies()->where('status', 'active')->latest()->first();

// nova propriedade: activeStrategy
$strategy?->only(['title', 'summary', 'editorial_line', 'pillars'])
```

`toArray()` inclui `active_strategy`. O strategist não lê o campo.

### Config

```php
'copywriter' => [
    'model' => env('AI_MODEL_COPYWRITER', env('AI_MODEL_DEFAULT', 'claude-opus-4-8')),
    'effort' => 'high',       // xhigh exigiria max_tokens>=64000 + streaming
    'max_tokens' => 16000,
    'batch_size' => 5,
],
```

5 peças com caption e hashtags é ~2500-3500 tokens de saída, folgado em 16k. Se
truncar na geração real, `stop_reason: max_tokens` já vira `provider_failed`
(retentável) — o modo de falha existe e é correto, não pré-otimizar.

### Persistência em `contents`

O `RunAgentJob` já envolve `persist` em `DB::transaction`: ou as 5 entram, ou
nenhuma. Um lote meio-gravado pareceria sucesso.

Cada peça → uma linha:

```php
Content::create([
    'workspace_id' => $project->workspace_id,
    'project_id' => $project->id,
    'title' => $piece['title'],
    'caption' => $piece['caption'],
    'cta' => $piece['cta'],
    'hashtags' => $piece['hashtags'],
    'format' => $piece['format'],
    'channel' => $piece['channel'],
    'status' => 'idea',              // a IA propoe, o humano promove
    'source' => 'ai',                // sustenta o chip "Gerado por IA"
    'origin_ai_run_id' => $run->id,  // custo do run se divide pelas 5 pecas
    'created_by' => $run->created_by,
]);
```

- **`status: 'idea'`** — primeiro valor do enum, espírito de "estratégia nasce
  `draft`". O copywriter não pula status.
- **`source: 'ai'` + `origin_ai_run_id`** — exatamente o que a migration
  documenta ("sustenta o chip 'Gerado por IA' e dá rastreabilidade de custo").
- **`pillar` não é persistido** — `contents` não tem coluna. Fica no
  `ai_runs.output` (o job grava o output inteiro) e serve ao `validate()`. Se um
  dia `contents` ganhar `pillar_id`, o dado já está no run. YAGNI.
- **Nulos deliberados:** `campaign_id`, `objective_id`, `image_prompt`,
  `image_asset_id`, `scheduled_for`, `assignee_id`. A peça nasce solta — o
  copywriter escreve texto, não planeja calendário nem atribui tarefas.
- **`created_by`** herda do run — não há ator "sistema" (ADR-11).

### `Content` model

**Não existe ainda** — criar. Model magro no padrão do `Strategy`: `$fillable`
com as colunas acima, cast de `hashtags` para array, `WorkspaceMemberScope`,
relação `belongsTo(Project)`.

Confirmado na migration: `campaign_id` e `objective_id` são `nullable`, então a
peça nascer solta não quebra o insert. `caption` e `cta` também são `nullable`
— o `validate()` é o dono da regra de que devem ter conteúdo, não o banco.

### Rota e controller

`POST /projects/{project}/copy:generate` → `CopyController::generate`. Controller
novo (não um método no `StrategyController`), responsabilidades diferentes.

Quatro guardas, **a ordem é a regra**:

1. `Gate::authorize('update', $project)`.
2. Sem estratégia `active` → **422** ("Aprove uma estrategia antes de gerar
   conteudo"), não enfileira. É a pré-condição mais barata e específica.
3. Geração de copy em andamento (`emAndamento` filtra por `agent='copywriter'`)
   → **409**.
4. Orçamento estourado → **402** (mesmo shape do strategist).

Senão: cria `AiRun` com `agent: 'copywriter'`, `input: {project_id, strategy_id}`,
despacha `RunAgentJob`, devolve **202** `{ai_run_id}`.

O `catch (UniqueConstraintViolationException)` do índice devolve 409 — mesmo
padrão do strategist, para a corrida que escapar da checagem explícita.

## Contrato

`POST /api/v1/projects/{id}/copy:generate`:
- **202** `{ai_run_id}` — enfileirado
- **422** `{message}` — sem estratégia ativa
- **409** `{message}` — geração de copy já em andamento
- **402** `{message, spent_cents, limit_cents}` — orçamento

Polling em `GET /ai-runs/{id}` (já existe). Peças aparecem em `contents` com
`status='idea'`, `source='ai'`, `origin_ai_run_id`.

## Testes

Backend, no padrão das fatias anteriores.

`CopywriterAgentTest` (unit):
- `validate()` aceita 5 peças com enums válidos
- rejeita ≠ 5 peças
- rejeita `format`/`channel` fora dos enums
- `instructions()` mencionam `forbidden_words` e `required_words`
- `schema()` não usa `minItems`/`maxItems`/`minLength` (a API rejeita)
- `userMessage()` com `activeStrategy` nulo lança `LogicException`

`AnthropicProviderTest` já cobre o transporte — o copywriter usa o mesmo provider.

`CopyGenerationTest` (feature), com provider fake e `MockProvider`:
- caminho feliz: 202, run `succeeded`, **5 linhas em `contents`** com
  `source='ai'`, `status='idea'`, `origin_ai_run_id` = run
- as 5 peças numa transação: um provider que devolve peça inválida (falha no
  `validate`) → **0 linhas** em `contents` (nada meio-gravado)
- 422 sem estratégia ativa, não cria run
- 409 com copy em andamento; **mas** uma estratégia em andamento **não** bloqueia
  copy (o índice por-agente)
- 402 orçamento estourado
- isolamento de tenant: projeto de outro workspace → 404
- recusa → `error_code='refused'`, nenhuma peça

O `MockProvider` precisa de um fixture novo para o schema do copywriter (ele hoje
só conhece o do strategist — decide pelo formato do schema).

`AgentContext`: um teste de que `forProject` carrega a estratégia `active`, e que
ignora estratégias `draft`/`archived`.

### Migration

`(project_id, agent)` no índice parcial. Reescreve o índice existente: `down`
volta ao `(project_id)` de antes.

### Verificação final

`php artisan test` verde. Depois, geração real com `MockProvider` via
`queue:work`, e uma geração com a API (`AI_PROVIDER=anthropic`) confirmando que
as 5 peças saem coerentes com a estratégia da 2F AutoShop — e que respeitam
`forbidden_words`. Voltar para `mock` ao fim.

## Critério de sucesso

- `php artisan test` verde, com os testes de agente, geração e `AgentContext`.
- `POST /copy:generate` devolve 202 e grava 5 linhas em `contents`.
- Uma peça inválida no lote → nenhuma linha gravada (transação).
- 422 sem estratégia; 409 por-agente; 402 orçamento.
- Geração real: 5 peças coerentes, `forbidden_words` respeitadas.
