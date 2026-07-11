# Agente `social_media` — distribuição no calendário

**Problema:** o `CopywriterAgent` produz peças e a tela de Conteúdo as move até `approved`. Ali elas param. `contents.scheduled_for` existe desde a primeira migration, o status `scheduled` existe no enum, e o índice `(project_id, scheduled_for)` já diz no comentário que "o calendario le por aqui" — mas nada agenda. O `ContentController` deixou `scheduled` de fora do fluxo linear com o comentário: "exigem agendar e exportar, que nao existem".

**Esta fatia dá a `approved` o seu próximo passo.** O terceiro dos sete agentes (`social_media`, na doc: *plano + campanhas → distribuição no calendário*) lê as peças aprovadas e as espalha por uma janela de tempo.

## Escopo (decidido com o Djair)

| Decisão | Escolha | Por quê |
|---|---|---|
| O que o agente faz | **Só agenda as peças `approved`** | `campaigns` não tem controller, nem tela, nem uma linha no banco. Puxá-la agora dobra a fatia sem entregar valor. |
| Quem define a janela | **A UI manda** `starts_on` + `days` | A saída fica determinística e validável ("as datas caem na janela?"). Se o agente escolhesse a janela, não haveria como dizer que uma data está errada. |
| Onde aparece | **6ª coluna "Agendado"** na `ContentPage` | Reaproveita o board que acabou de ser validado. A tela de calendário de verdade (grid mensal, arrastar para remarcar) é uma fatia própria. |

**Fora do escopo:** campanhas, `published`, exportação, calendário mensal, remarcar arrastando.

## A regra de fluxo

`approved → scheduled` **é exclusivo do agente**, e não da API humana: agendar exige uma data, e o `PATCH /contents/{id}` só carrega `status`. Um humano que quisesse agendar pelo board não teria como dizer *quando*.

O que o humano pode fazer com uma peça agendada:

- **Desagendar** (`scheduled → approved`): limpa `scheduled_for` na mesma transação. Sem isso, a peça voltaria para Aprovado carregando uma data fantasma.
- **Arquivar** (`scheduled → archived`), como de qualquer estado ativo.

`FLOW` continua `['idea','production','review','approved']`. `scheduled` entra no `isValidTransition` como caso explícito — **não** como quinto elemento do array. Se entrasse no array, o botão "Avançar" de uma peça aprovada habilitaria no board e o clique tomaria 422: a UI prometeria o que a API recusa.

## Backend

**Nenhuma migration.** `scheduled_for` e o status `scheduled` já existem.

**`AgentContext`** ganha `?array $scheduleWindow` e `?array $approvedContents`, e `forProject(Project $project, array $input = [])`. Segue o padrão travado na fatia do copywriter: *agente que precisa de mais que o projeto estende o `AgentContext`, não a interface `Agent`* — os cinco agentes futuros não são tocados.

As duas chaves novas só entram no `toArray()` **quando há janela**. O strategist e o copywriter foram verificados contra a API real; o prompt deles não muda um byte por causa desta fatia — e nem paga tokens por uma lista de peças que não vai usar.

A janela vem de `ai_runs.input` (`{starts_on, days}`), que o `RunAgentJob` passa para o `forProject`. As peças aprovadas são buscadas **no momento da execução**, não no momento do POST: mesma disciplina da estratégia ativa no copywriter — o `input` é registro de intenção, o contexto é a fonte da verdade.

**`SocialMediaAgent`:**

- `schema`: `{ schedule: [{ content_id: integer, scheduled_for: string, reason: string }] }`. Sem `minItems`/`maxItems` — a API os rejeita (ver gotchas do Opus 4.8); "uma entrada por peça" vira prosa na instrução e regra no `validate()`.
- `validate()`: uma entrada por peça aprovada, exatamente; nenhum `content_id` fora do conjunto; nenhum id repetido; `scheduled_for` parseável e **dentro da janela**. Fora disso é `OutputRejectedException` — o `RunAgentJob` já retenta uma vez.
- `persist()`: para cada entrada, grava `scheduled_for`, move para `scheduled` e escreve o `ContentRevision` (`approved → scheduled`) com `user_id = run.created_by`. Não existe ator "sistema" no modelo (ADR-11); o dono da execução responde pelo movimento.

**`ScheduleController@generate`** — espelha o `CopyController`, com as guardas na mesma ordem: sem peça aprovada → **422**; execução de `social_media` em andamento → **409** (o índice parcial é por-agente); orçamento estourado → **402**; senão **202** `{ai_run_id}`. Rota: `POST /projects/{project}/schedule:generate`. `starts_on` é uma data (default: amanhã), `days` um inteiro de 1 a 60 (default: 14).

**`MockProvider`** ganha o terceiro fixture. Ele não pode inventar ids: o `validate()` os rejeitaria e a geração falharia em dev. O fixture lê os ids e a janela do `<context>` do próprio `userMessage` e distribui uma peça por dia às 10:00.

## Frontend

- `ContentStatus` ganha `'scheduled'`; `Content` ganha `scheduled_for: string | null`.
- `groupByStatus` ganha a coluna **Agendado**, entre Aprovado e Arquivado.
- `ContentCard` mostra a data agendada quando existe. Em `scheduled`: "Avançar" desabilitado (`published` não existe nesta fatia), "Voltar" desagenda, "Arquivar" segue valendo.
- `ContentPage` ganha o botão **"Agendar aprovadas"** com dois campos de janela (data de início, nº de dias). O botão desabilita quando não há peça aprovada.
- `useGeneration` passa a aceitar um corpo opcional no POST, guardado numa ref para que o `retry()` reenvie a mesma janela. Os dois chamadores atuais não passam corpo e não mudam.

## Critério de sucesso

- `php artisan test` verde, incluindo: `validate()` rejeitando id fora do conjunto, id repetido e data fora da janela; `persist()` gravando data + status + revisão; controller devolvendo 202/422/409/402.
- `pnpm test` + `pnpm build` + `oxlint` limpos, com os testes atuais da `ContentPage` e da `StrategyPage` intactos.
- No browser (mock): gerar → aprovar peças → "Agendar aprovadas" → elas migram para Agendado com data → "Voltar" desagenda e limpa a data → `content_revisions` registra `approved → scheduled`.
