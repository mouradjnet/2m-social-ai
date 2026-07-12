# Agente `reviewer` — veredito e violações

**Problema:** o board tem uma coluna chamada **Revisão** desde a primeira fatia, e nada revisa. A peça passa por ela só porque é o caminho até Aprovado. O `reviewer` é o 4º dos sete agentes (na doc: *conteúdo + Brand Profile → veredito + lista de violações*), e é ele que dá sentido à coluna.

## Escopo (decidido com o Djair)

| Decisão | Escolha | Por quê |
|---|---|---|
| Onde guarda | **Tabela `content_reviews` nova** | O veredito é dado, não prosa. Em `content_comments` ele viraria string, e contar/filtrar/mostrar chip exigiria parsear texto depois. |
| O que revisa | **O lote da coluna Revisão** | Mesmo padrão do `social_media`, que agenda o lote de Aprovado. Um botão, uma execução, o contexto da marca enviado uma vez só. |
| Efeito do veredito | **Só informa** | A IA propõe, o humano promove — regra do projeto. Um falso negativo não pode aprovar peça sozinho. |

**Fora do escopo:** bloquear a promoção de uma peça reprovada, resolver violação, reescrever a peça (isso é o copywriter), `content_comments`.

## Backend

**Migration nova** (a primeira desde a fundação) — `content_reviews`:

| Coluna | |
|---|---|
| `content_id` | FK, cascade |
| `ai_run_id` | FK — toda revisão veio de uma execução, e o custo é rastreável por ela |
| `verdict` | enum `pass` / `fail` |
| `summary` | text — uma frase |
| `violations` | jsonb — `[{rule, excerpt, suggestion}]`, vazio quando `pass` |
| `created_at` | append-only, sem `updated_at` (mesma disciplina do `content_revisions`) |

Sem `workspace_id`: as tabelas filhas de `contents` (`content_revisions`, `content_comments`) não carregam tenant — quem o resolve é a peça.

**Append-only.** Uma peça pode ser revisada várias vezes; o histórico fica. A UI mostra a **última**.

**`AgentContext`** ganha `?array $reviewContents`, carregado quando o input do run traz `content_ids` — simétrico ao `scheduleWindow`: o controller registra a intenção ("revise estas"), e o contexto busca **no momento da execução** o texto atual delas (`where status = review`). Diferente das aprovadas do social_media, aqui vai o **texto inteiro** (caption, cta, hashtags): é ele que está sendo julgado.

**`ReviewerAgent`:**

- `schema`: `{ reviews: [{ content_id, verdict, summary, violations: [{rule, excerpt, suggestion}] }] }`.
- `instructions`: julga contra o **Brand Profile** — tom de voz, público, `forbidden_words` (violação dura), `required_words`, e a linha editorial da estratégia ativa. `excerpt` cita o trecho ofensor; `suggestion` diz como corrigir.
- `validate`: uma entrada por peça do lote, exatamente; nenhum id de fora; nenhum repetido; `fail` **exige** ao menos uma violação e `pass` **exige** nenhuma — um veredito que não bate com a lista é uma saída incoerente, não uma opinião.
- `persist`: uma linha em `content_reviews` por peça. **Não toca no `status`.**

**`ReviewController@generate`** — espelha o `ScheduleController`: sem peça em Revisão → **422**; execução de `reviewer` em andamento → **409**; orçamento → **402**; senão **202**. Rota `POST /projects/{project}/review:generate`.

**`ContentController@index`** passa a carregar a **última review** de cada peça (`hasOne ... latestOfMany`), para o board mostrar o veredito sem uma segunda chamada.

## Frontend

- `Content` ganha `latest_review: ContentReview | null`.
- `ContentCard` mostra o veredito: chip **“✓ Sem violações”** ou **“⚠ N violações”**, e, quando reprovada, as violações (regra + sugestão) no próprio card — quem vai corrigir precisa ver o que está errado sem clicar.
- `ContentPage` ganha **“Revisar (N)”**, desabilitado quando a coluna Revisão está vazia. Usa o mesmo `useGeneration` (endpoint na chamada).

## Critério de sucesso

- `php artisan test` verde, incluindo: `fail` sem violação e `pass` com violação **rejeitados**; id fora do lote rejeitado; persist gravando a review sem mexer no status; 422/409/402 do controller.
- `pnpm test` + `pnpm build` + `oxlint` limpos, com os testes atuais intactos.
- No browser: peças em Revisão → “Revisar” → cards mostram veredito e violações → o status **não** mudou sozinho.
