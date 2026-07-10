# 2M Social AI — Contratos de API

REST, versionada em `/api/v1`. Autenticação por token Bearer (Laravel Sanctum).
Respostas em JSON via API Resources. Erros no formato padrão do Laravel (`{message, errors}`).

## Convenções

- **O workspace vem sempre da rota** (ADR-02). Não existe "workspace atual" implícito.
- Recursos aninhados só até um nível. Depois disso, o recurso é acessado pelo próprio ID e o workspace é resolvido a partir dele — sempre com Policy.
- `:action` no final do path indica operação não-CRUD (`:generate`, `:accept`).
- Rotas de IA respondem **202 Accepted** com `{ai_run_id}` e nunca bloqueiam.
- Paginação: `?page=&per_page=` (máx. 100), resposta com `meta.total`.

---

## Autenticação

```
POST   /api/v1/auth/register        {name, email, password}
POST   /api/v1/auth/login           {email, password}          → {token}
POST   /api/v1/auth/logout
GET    /api/v1/me                   → user + workspaces
```

## Workspaces e membros

```
GET    /api/v1/workspaces
POST   /api/v1/workspaces                    {name}
GET    /api/v1/workspaces/{workspace}
PATCH  /api/v1/workspaces/{workspace}        {name}
DELETE /api/v1/workspaces/{workspace}        owner apenas

GET    /api/v1/workspaces/{workspace}/members
PATCH  /api/v1/workspaces/{workspace}/members/{member}   {role}
DELETE /api/v1/workspaces/{workspace}/members/{member}

POST   /api/v1/workspaces/{workspace}/invitations        {email, role}
GET    /api/v1/workspaces/{workspace}/invitations
DELETE /api/v1/invitations/{invitation}
POST   /api/v1/invitations/{token}:accept                não exige ser membro
```

## Projetos e objetivos

```
GET    /api/v1/workspaces/{workspace}/projects           ?status=&q=
POST   /api/v1/workspaces/{workspace}/projects
GET    /api/v1/projects/{project}
PATCH  /api/v1/projects/{project}
DELETE /api/v1/projects/{project}

GET    /api/v1/projects/{project}/objectives
POST   /api/v1/projects/{project}/objectives
PATCH  /api/v1/objectives/{objective}
DELETE /api/v1/objectives/{objective}
```

## Perfil da Marca

O design é um **wizard de 4 passos**, não um formulário. A API precisa aceitar salvamento parcial.

```
GET    /api/v1/projects/{project}/brand-profile
PATCH  /api/v1/projects/{project}/brand-profile
```

`PATCH` com semântica de merge parcial. O cliente envia só os campos do passo atual:

```jsonc
// Passo 1 — Identidade
{ "brand_name": "Acme", "description": "...", "logo_path": "..." }

// Passo 2 — Público-Alvo
{ "audience": "...", "persona": "..." }

// Passo 3 — Posicionamento
{ "differentiators": "...", "competitors": [{"name":"X","url":"..."}],
  "tone_of_voice": "...", "required_words": [], "forbidden_words": [] }

// Passo 4 — Links Sociais
{ "website": "...", "instagram": "...", "linkedin": "..." }
```

A resposta sempre traz `completion` — quais passos estão completos — para o stepper renderizar sem lógica duplicada no frontend:

```json
{ "data": { ... }, "completion": { "identity": true, "audience": true,
  "positioning": false, "social": false, "percent": 50 } }
```

**Não usamos `PUT`.** `PUT` implica payload completo, e o wizard nunca tem o payload completo antes do passo 4.

## Pesquisa IA

```
GET    /api/v1/projects/{project}/researches      ?type=
POST   /api/v1/projects/{project}/researches      {type, query}   → 202 {ai_run_id}
GET    /api/v1/researches/{research}              inclui insights

POST   /api/v1/research-insights/{insight}:convert  → 201 content
```

`:convert` cria um `contents` com `source = 'research'` e grava `converted_content_id` no insight. Chamar duas vezes retorna 409.

## Estratégia e planejamento

```
GET    /api/v1/projects/{project}/strategies
POST   /api/v1/projects/{project}/strategies:generate     → 202 {ai_run_id}
GET    /api/v1/strategies/{strategy}
PATCH  /api/v1/strategies/{strategy}                      edição humana
POST   /api/v1/strategies/{strategy}/plans:generate       {period_start, period_end} → 202
GET    /api/v1/content-plans/{plan}
```

## Campanhas

```
GET    /api/v1/projects/{project}/campaigns    ?status=
POST   /api/v1/projects/{project}/campaigns
GET    /api/v1/campaigns/{campaign}
PATCH  /api/v1/campaigns/{campaign}
DELETE /api/v1/campaigns/{campaign}
POST   /api/v1/campaigns/{campaign}/contents:generate   → 202 {ai_run_id}
```

## Conteúdo

```
GET    /api/v1/projects/{project}/contents
       ?status=&channel=&format=&campaign_id=&assignee_id=&q=
POST   /api/v1/projects/{project}/contents
GET    /api/v1/contents/{content}
PATCH  /api/v1/contents/{content}      NÃO aceita o campo `status`
DELETE /api/v1/contents/{content}
```

### Transição de status

Único caminho de escrita em `status` (ADR-05).

```
POST   /api/v1/contents/{content}:transition
```

```jsonc
{ "to": "review" }
{ "to": "production", "comment": "Falta o CTA." }   // rejeição exige comentário
{ "to": "scheduled",  "scheduled_for": "2026-07-20T09:00:00-03:00" }
```

Respostas de erro possíveis:

| Código | Quando |
|---|---|
| `403` | papel do usuário não permite a transição |
| `409` | transição inválida a partir do status atual |
| `422` | pré-condição não satisfeita (`caption` vazia, palavra proibida, data no passado) |

O corpo do `422` diz **qual** guardrail falhou:

```json
{ "message": "O conteúdo viola o perfil da marca.",
  "errors": { "forbidden_words": ["barato"] } }
```

### Geração, comentários e histórico

```
POST   /api/v1/contents/{content}:generate   {agent: "copywriter"|"designer"|"seo"|"reviewer"} → 202
GET    /api/v1/contents/{content}/revisions
GET    /api/v1/contents/{content}/comments
POST   /api/v1/contents/{content}/comments   {body}
PATCH  /api/v1/comments/{comment}            {resolved: true}
```

## Calendário

Leitura pura sobre `contents` (ADR-04). Não existe `POST /calendar`.

```
GET    /api/v1/projects/{project}/calendar
       ?from=2026-07-01&to=2026-07-31&channel=&status=&campaign_id=
```

Agrupado por dia, com o mínimo para renderizar a grade:

```json
{ "data": { "2026-07-20": [
    { "id": 41, "title": "...", "channel": "instagram", "format": "reel",
      "status": "scheduled", "scheduled_for": "2026-07-20T09:00:00-03:00",
      "campaign": {"id": 3, "name": "Black Friday", "color": "#10b981"} }
]}}
```

## Exportação (o que "Publicação" significa no MVP — ADR-03)

```
GET    /api/v1/contents/{content}/export?format=json|txt
POST   /api/v1/projects/{project}/contents:export   {ids: [...]}   → arquivo .zip
```

Devolve legenda, CTA, hashtags e a imagem. O usuário publica na mão.

## Biblioteca

```
GET    /api/v1/workspaces/{workspace}/assets     ?type=&project_id=
POST   /api/v1/workspaces/{workspace}/assets     multipart
DELETE /api/v1/assets/{asset}

GET    /api/v1/workspaces/{workspace}/snippets   ?kind=
POST   /api/v1/workspaces/{workspace}/snippets
PATCH  /api/v1/snippets/{snippet}
DELETE /api/v1/snippets/{snippet}

GET    /api/v1/workspaces/{workspace}/templates  ?kind=
POST   /api/v1/workspaces/{workspace}/templates
DELETE /api/v1/templates/{template}
```

Os filtros da tela de Biblioteca (`Tudo` · `Postagens` · `Imagens` · `Campanhas` · `Modelos`) são resolvidos **no frontend**, agregando `assets` + `snippets` + `templates` + `contents`. Não criamos um endpoint `/library` que unifique tipos heterogêneos — seria um endpoint sem forma estável.

## Analytics

Só o que é computável sem dados das redes (ADR-08).

```
GET    /api/v1/projects/{project}/analytics/summary?from=&to=
GET    /api/v1/projects/{project}/analytics/calendar-score
GET    /api/v1/workspaces/{workspace}/analytics/productivity?from=&to=
```

`summary` devolve: produzidos, aprovados, publicados, tempo médio de aprovação, aderência ao prazo, cobertura de campanha, cobertura de objetivos.

`calendar-score` devolve a pontuação e **a decomposição dela** — sem explicar de onde vem, um número de 0 a 100 é ruído:

```json
{ "score": 72, "breakdown": {
    "format_diversity": 80, "temporal_distribution": 65,
    "objective_coverage": 90, "editorial_line_adherence": 55 } }
```

## Execuções de IA

```
GET    /api/v1/ai-runs/{run}
```

```json
{ "id": 918, "agent": "strategist", "status": "running",
  "created_at": "...", "output": null, "error": null }
```

Estados: `queued` → `running` → `succeeded` | `failed`.
O frontend faz polling a cada 2s (ADR-07). Em `failed`, `error` traz mensagem legível — nunca o stack trace.

## Dashboard

Um endpoint, porque a tela é uma composição fixa e N chamadas separadas fariam N round-trips para renderizar uma tela só.

```
GET    /api/v1/workspaces/{workspace}/dashboard
```

Devolve: projetos ativos, campanhas ativas, conteúdos em revisão, próximas publicações, produtividade, atividades recentes.

## Notificações

```
GET    /api/v1/workspaces/{workspace}/notifications   ?unread=1
POST   /api/v1/notifications/{notification}:read
POST   /api/v1/workspaces/{workspace}/notifications:read-all
```

---

## Códigos de status usados

| Código | Significado no contexto |
|---|---|
| `200` | leitura ou atualização bem-sucedida |
| `201` | recurso criado |
| `202` | job de IA enfileirado; consulte `ai_runs` |
| `204` | exclusão |
| `402` | orçamento de IA do workspace esgotado |
| `403` | papel insuficiente |
| `409` | conflito de estado (transição inválida, insight já convertido) |
| `422` | validação ou guardrail |
| `429` | rate limit |
