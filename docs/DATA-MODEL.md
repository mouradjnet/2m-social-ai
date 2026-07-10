# 2M Social AI — Modelo de Dados

PostgreSQL 16. Todas as tabelas têm `id bigserial`, `created_at`, `updated_at` salvo indicação contrária.

## Regra de ouro do tenant

**Toda tabela de conteúdo carrega `workspace_id`, mesmo quando poderia derivá-lo via join.** É desnormalização deliberada: permite um Global Scope no Eloquent que filtra por `workspace_id` sem join, e transforma um `where` esquecido em zero linhas em vez de vazamento entre clientes.

## Diagrama de relações

```
users ──┬── workspace_members ──┬── workspaces
        │                        │
        └── (assignee, author)   └── projects ──┬── objectives
                                                 ├── brand_profiles (1:1)
                                                 ├── researches ── research_insights
                                                 ├── strategies ── content_plans
                                                 ├── campaigns
                                                 └── contents ──┬── content_revisions
                                                                └── content_comments

workspaces ──┬── assets
             ├── snippets
             ├── templates
             ├── ai_runs
             └── activity_logs
```

---

## Identidade e tenancy

### `users`
Padrão Laravel: `name`, `email` (unique), `password`, `email_verified_at`, `remember_token`.

### `workspaces`
| coluna | tipo | nota |
|---|---|---|
| `name` | varchar(120) | |
| `slug` | varchar(140) unique | |
| `owner_id` | fk users | |
| `plan` | varchar(30) | `free` no MVP; existe para não migrar depois |
| `trial_ends_at` | timestamptz null | |

### `workspace_members`
| coluna | tipo | nota |
|---|---|---|
| `workspace_id` | fk workspaces cascade | |
| `user_id` | fk users cascade | |
| `role` | enum | `owner` `admin` `editor` `reviewer` `viewer` |
| `joined_at` | timestamptz | |

`unique (workspace_id, user_id)`.

### `workspace_invitations`
`workspace_id`, `email`, `role`, `token` (unique, hash), `invited_by`, `expires_at`, `accepted_at` null.

`unique (workspace_id, email)` entre convites não aceitos.

---

## Projeto e marca

### `projects`
| coluna | tipo | nota |
|---|---|---|
| `workspace_id` | fk | |
| `name` | varchar(120) | |
| `company` | varchar(160) null | |
| `segment` | varchar(80) null | |
| `description` | text null | |
| `owner_user_id` | fk users null | o "Responsável" do spec |
| `status` | enum | `active` `paused` `archived` |
| `image_path` | varchar null | |
| `color` | char(7) null | hex |

`index (workspace_id, status)`.

### `objectives`
Fonte única dos objetivos (ADR-09).

`project_id`, `title`, `description` null, `target_metric` varchar null, `due_date` date null, `position` smallint.

### `brand_profiles`
Um por projeto: `unique (project_id)`.

| coluna | tipo |
|---|---|
| `project_id` | fk unique |
| `brand_name` | varchar(160) |
| `description` | text null |
| `products` | jsonb — array de string |
| `services` | jsonb — array de string |
| `audience` | text null |
| `persona` | text null |
| `tone_of_voice` | text null |
| `differentiators` | text null |
| `competitors` | jsonb — `[{name, url}]` |
| `website` `instagram` `facebook` `linkedin` `tiktok` `youtube` | varchar null |
| `required_words` | jsonb — array de string |
| `forbidden_words` | jsonb — array de string |
| `colors` | jsonb — array de hex |
| `logo_path` | varchar null |

> `required_words` e `forbidden_words` são validados **deterministicamente** no Agente Revisor, não confiando no LLM. Ver [AI-LAYER.md](AI-LAYER.md#guardrails).

---

## Pesquisa

### `researches`
| coluna | tipo | nota |
|---|---|---|
| `workspace_id` `project_id` | fk | |
| `type` | enum | `trend` `market` `competitor` `faq` `keyword` `news` `hashtag` |
| `query` | text | |
| `status` | enum | `queued` `running` `done` `failed` |
| `ai_run_id` | fk ai_runs null | |
| `created_by` | fk users | |

### `research_insights`
`research_id`, `title`, `body` text, `score` smallint null, `converted_content_id` fk contents null.

`converted_content_id` materializa *"toda pesquisa poderá ser transformada em conteúdo"* e evita conversão duplicada.

---

## Estratégia e planejamento

### `strategies`
Duradoura — a linha editorial do projeto.

`workspace_id`, `project_id`, `title`, `summary` text, `editorial_line` text, `pillars` jsonb (`[{name, weight, description}]`), `status` enum (`draft` `active` `archived`), `ai_run_id` null.

### `content_plans`
Periódica — o "planejamento" do spec (quantos posts, quando, de que tipo).

| coluna | tipo |
|---|---|
| `strategy_id` | fk |
| `period_start` `period_end` | date |
| `posts_count` | smallint |
| `distribution` | jsonb — `{channel: count}` |
| `best_days` | jsonb — array de weekday |
| `best_times` | jsonb — array de `"HH:MM"` |
| `format_mix` | jsonb — `{format: count}` |
| `ai_run_id` | fk null |

> No MVP, `best_days`/`best_times` são **sugestões do modelo baseadas em boas práticas do segmento**, não em dados de desempenho — que não existem sem integração com as redes (ADR-03). O frontend deve rotulá-las como sugestão, nunca como métrica.

---

## Campanhas e conteúdo

### `campaigns`
`workspace_id`, `project_id`, `name`, `description` null, `starts_at` `ends_at` date, `color` char(7) null, `status` enum (`planned` `active` `done` `archived`).

### `contents`
O coração do sistema.

| coluna | tipo | nota |
|---|---|---|
| `workspace_id` `project_id` | fk | |
| `campaign_id` | fk null | |
| `title` | varchar(200) | |
| `summary` | text null | |
| `caption` | text null | a legenda |
| `cta` | varchar(280) null | |
| `hashtags` | jsonb | array de string sem `#` |
| `objective_id` | fk objectives null | |
| `format` | enum | `post` `carousel` `reel` `story` `video` `article` `thread` |
| `channel` | enum | `instagram` `facebook` `linkedin` `tiktok` `youtube` `blog` |
| `image_prompt` | text null | |
| `image_asset_id` | fk assets null | |
| `status` | enum | ver máquina de estados abaixo |
| `assignee_id` | fk users null | |
| `scheduled_for` | timestamptz null | |
| `published_at` | timestamptz null | marcado manualmente pelo usuário |
| `source` | enum | `manual` `ai` `research` — sustenta o chip "Gerado por IA" das telas |
| `origin_ai_run_id` | fk ai_runs null | rastreabilidade: qual geração produziu esta peça |
| `created_by` | fk users | |

Índices: `(workspace_id, project_id, status)`, `(project_id, scheduled_for)` — este último é o que o calendário consulta.

### Máquina de estados de `contents.status`

```
idea ──▶ production ──▶ review ──┬──▶ approved ──▶ scheduled ──▶ published ──▶ archived
                ▲                │                      │
                └────────────────┘ (rejeitado)          └──▶ approved (desagendar)
```

| transição | quem pode | pré-condição |
|---|---|---|
| `idea → production` | editor+ | — |
| `production → review` | editor+ | `caption` não vazio |
| `review → approved` | reviewer+ | passou no Agente Revisor |
| `review → production` | reviewer+ | exige comentário |
| `approved → scheduled` | editor+ | `scheduled_for` no futuro |
| `scheduled → approved` | editor+ | — |
| `scheduled → published` | editor+ | ação manual explícita |
| `published → archived` | editor+ | — |
| `* → archived` | admin+ | — |

Implementada em `Domain/Content/ContentWorkflow`, exposta por `POST /contents/{id}/transition` (ADR-05). Nenhum outro caminho escreve em `status`.

### `content_revisions`
O "Histórico" do spec. Append-only.

`content_id`, `user_id`, `from_status`, `to_status`, `changes` jsonb (diff dos campos), `created_at`.

### `content_comments`
`content_id`, `user_id`, `body` text, `resolved_at` null.

---

## Biblioteca

*"Posts, Imagens, Campanhas, Hashtags, CTAs, Prompts, Templates, Vídeos, Documentos. Tudo reutilizável."* — três tabelas cobrem os nove tipos.

### `assets` — imagens, vídeos, documentos
`workspace_id`, `project_id` null (null = compartilhado no workspace), `type` enum (`image` `video` `document`), `disk`, `path`, `mime`, `size_bytes`, `width` null, `height` null, `checksum` (sha256, para deduplicar), `created_by`.

### `snippets` — hashtags, CTAs, prompts, legendas
`workspace_id`, `project_id` null, `kind` enum (`hashtag_set` `cta` `prompt` `caption`), `title`, `body` text, `tags` jsonb, `usage_count` int default 0.

### `templates`
`workspace_id`, `kind` enum (`content` `campaign` `strategy`), `name`, `payload` jsonb.

Um "post reutilizável" é um `template` de `kind = content` — não um `content` com flag, que poluiria o calendário e o Analytics.

---

## IA e auditoria

### `ai_runs`
Tabela central da camada de IA. Toda chamada a um modelo passa por aqui.

| coluna | tipo | nota |
|---|---|---|
| `workspace_id` | fk | |
| `project_id` | fk null | |
| `agent` | enum | `strategist` `copywriter` `social_media` `designer` `seo` `analytics` `reviewer` |
| `provider` | varchar(30) | `anthropic` no MVP |
| `model` | varchar(60) | ex. `claude-opus-4-8` |
| `status` | enum | `queued` `running` `succeeded` `failed` |
| `input` | jsonb | contexto enviado |
| `output` | jsonb null | resposta validada contra o schema |
| `input_tokens` `output_tokens` | int null | |
| `cache_read_tokens` `cache_write_tokens` | int null | ver AI-LAYER |
| `cost_cents` | int null | |
| `latency_ms` | int null | |
| `error` | text null | |
| `created_by` | fk users | |

Índices: `(workspace_id, created_at)` para o orçamento mensal; `(status)` para o worker.

> **LGPD:** `input` e `output` contêm texto do usuário. Job agendado purga registros com mais de 90 dias. Definir antes do primeiro usuário real.

### `activity_logs`
`workspace_id`, `user_id` **not null**, `subject_type`, `subject_id`, `action`, `meta` jsonb, `created_at`.

Alimenta "Atividades recentes" do Dashboard. `user_id` é obrigatório de propósito: **não existe ator "sistema"** (ADR-11). Toda linha do feed tem um humano responsável.

### `notifications`
Tabela padrão do Laravel (`notifications`), com `notifiable_type/id`.

---

## O que não existe, e por quê

| Tabela ausente | Motivo |
|---|---|
| `calendars` | O calendário é uma view sobre `contents` (ADR-04) |
| `analytics_*` | Métricas do MVP são agregações on-the-fly; sem volume que justifique tabela materializada (ADR-08) |
| `social_accounts` / `oauth_tokens` | Sem integração com redes no MVP (ADR-03) |
| `subscriptions` / `invoices` | Sem pagamentos no MVP (ADR-10) |
