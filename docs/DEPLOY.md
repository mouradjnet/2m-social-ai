# Deploy

**Onde:** Render, pelo blueprint `render.yaml` (a infra está declarada no repo — não em cliques num painel).

**A forma:** o Laravel serve a API **e** a SPA no mesmo domínio. Um único origin, sem CORS, e o `fetch('/api/v1/...')` do frontend continua relativo. O build do Vite entra em `public/` na imagem (ver `Dockerfile`), e o catch-all de `routes/web.php` devolve o `index.html` nos deep links.

## As três peças

| Serviço | O quê |
|---|---|
| `2m-social-ai` (web) | FrankenPHP servindo a API + a SPA |
| `2m-social-ai-worker` | **O mesmo container**, rodando `queue:work` |
| `2m-social-ai-db` | Postgres 16 |

**O worker não é opcional.** Toda geração de IA é um job (202 + polling, ADR-07). Sem worker, o usuário clica em "Gerar", o job entra na fila e **nada nunca volta** — a tela fica girando para sempre.

**Postgres não é negociável:** o modelo usa `jsonb` em quase toda tabela.

## Subir a primeira vez

1. Gere a chave da aplicação (localmente, uma vez):

   ```bash
   cd apps/api && php artisan key:generate --show
   ```

   Copie o valor (`base64:...`). Ele **não** vai para o repo.

2. No Render: **New → Blueprint**, aponte para `mouradjnet/2m-social-ai`, branch `main`. Ele lê o `render.yaml` e monta os três serviços.

3. O Render vai pedir as variáveis marcadas como `sync: false` — **nos dois serviços** (web e worker), com os mesmos valores:

   | Variável | Valor |
   |---|---|
   | `APP_KEY` | o `base64:...` do passo 1 |
   | `ANTHROPIC_API_KEY` | a chave real (a mesma do `.env` local) |
   | `APP_URL` | ainda não existe: preencha `https://2m-social-ai.onrender.com` (ajuste se o Render der outro nome) |

4. Aplique. O `entrypoint.sh` roda `config:cache`, `route:cache` e **`migrate --force`** no processo web (só nele — duas migrações simultâneas na primeira subida seriam corrida).

5. Depois do primeiro deploy, confirme a URL real do serviço e corrija `APP_URL` se ela tiver saído diferente.

## Depois de subir

- **Healthcheck:** `GET /up` (o Render usa; `SpaFallbackTest` garante que o catch-all da SPA não o engole).
- **A IA roda de verdade** (`AI_PROVIDER=anthropic`). Cada geração custa: estratégia ~4 centavos, lote de copy ~6. O teto é `AI_WORKSPACE_MONTHLY_BUDGET_CENTS` (5000 = US$ 50/mês por workspace) — estourou, a API responde **402** e nenhuma execução começa.
- **Para validar a infra sem gastar:** suba com `AI_PROVIDER=mock`, confirme o fluxo, e troque a variável depois. Nenhum código muda.

## Gotchas

- **O banco entra por `DB_URL`, não por `DB_HOST`/`DB_PORT`.** O `fromDatabase` do Render **não expõe** `host` nem `port` — só `connectionString`. O Laravel aceita: `config/database.php` lê `DB_URL` (formato `postgres://…`) antes dos campos separados. Verificado contra o Postgres local.
- **O blueprint repete as env vars nos dois serviços de propósito.** O Render não documenta suporte a âncora YAML (`&x` / `*x`), e um blueprint que não carrega não explica bem o porquê.
- **`config:cache` no build não funciona** — em build time não existe `DB_URL` nem `APP_KEY`. Por isso os caches são gerados no `entrypoint.sh`, com o ambiente já injetado.
- **O worker roda a mesma imagem**, só com outro comando. Não há um segundo Dockerfile para manter em sincronia.
- **`--tries=1` no worker é de propósito.** O `RunAgentJob` já trata as próprias falhas (marca o `ai_run` como `failed` com o `error_code`). Retentar o job inteiro **chamaria a API da Anthropic de novo** — e cobraria de novo.
- O `.env` nunca foi versionado, e não há nenhum `sk-ant-` no histórico. Mantenha assim: os segredos vivem só no painel do Render.
