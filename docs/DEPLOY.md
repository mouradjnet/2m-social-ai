# Deploy

**Onde:** Render, por blueprint (a infra está declarada no repo — não em cliques num painel).

**A forma:** o Laravel serve a API **e** a SPA no mesmo domínio. Um único origin, sem CORS, e o `fetch('/api/v1/...')` do frontend continua relativo. O build do Vite entra em `public/` na imagem (ver `Dockerfile`), e o catch-all de `routes/web.php` devolve o `index.html` nos deep links.

**O worker não é opcional.** Toda geração de IA é um job (202 + polling, ADR-07). Sem worker, o usuário clica em "Gerar", o job entra na fila e **nada nunca volta** — a tela fica girando para sempre. **Postgres também não é negociável:** o modelo usa `jsonb` em quase toda tabela.

## Dois blueprints, porque o free do Render não tem worker

| Arquivo | Serviços | Quando |
|---|---|---|
| **`render.yaml`** | **1 web (free)** — o container roda FrankenPHP **e** `queue:work` sob supervisor. O **Postgres é free do Neon**, fora do Render | **Sem cartão.** Validar, mostrar |
| `render.production.yaml` | web + worker + Postgres, `starter` (US$7 cada) | Cliente de verdade |

O plano free do Render **não oferece background worker**. Como o produto não funciona sem fila, o modo free roda os dois processos no mesmo container (`docker/supervisord.conf`). **É a mesma imagem** — o que muda é só o comando.

**O preço do free, honestamente:**

- o serviço **dorme** após ~15 min sem tráfego (o primeiro acesso depois demora ~1 min), e um job enfileirado **só anda quando alguém acorda o serviço**;
- o Neon **suspende o compute após 5 min** ocioso, então o primeiro acesso ainda paga um cold start do banco por cima do cold start do web.

### Por que o banco não é do Render

O Postgres free do Render **expira em 30 dias e depois é apagado** — não suspenso, apagado. O free do Neon **não expira** (0,5 GB de storage, 100 CU-hrs/mês, sem cartão). Como o banco inteiro cabe em ~150 KB, a folga é absurda.

**A troca:** o banco **deixou de ser declarado no blueprint**. `DB_URL` é `sync: false` e vive só no painel do Render — é a única peça da infra que não está no repo. Foi uma decisão consciente, não um esquecimento.

Para o modo pago, aponte o Blueprint para `render.production.yaml` (ou renomeie-o para `render.yaml`): lá nada dorme, nada expira, a fila é um processo próprio, e o banco volta a ser declarado (`fromDatabase`).

## Subir a primeira vez

1. Gere a chave da aplicação (localmente, uma vez):

   ```bash
   cd apps/api && php artisan key:generate --show
   ```

   Copie o valor (`base64:...`). Ele **não** vai para o repo.

2. Crie o Postgres no [Neon](https://console.neon.tech): projeto novo, **Postgres 16** (a mesma versão do dump), região **AWS US West 2 (Oregon)** — a mesma do serviço no Render, senão cada query atravessa os EUA. Copie a connection string **direta** (desligue *Connection pooling*).

3. No Render: **New → Blueprint**, aponte para `mouradjnet/2m-social-ai`, branch `main`. Ele lê o `render.yaml` e monta o serviço web.

4. O Render vai pedir as variáveis marcadas como `sync: false` (no modo pago, **nos dois serviços**, com os mesmos valores):

   | Variável | Valor |
   |---|---|
   | `APP_KEY` | o `base64:...` do passo 1 |
   | `DB_URL` | a string direta do Neon (`postgresql://…?sslmode=require`) |
   | `ANTHROPIC_API_KEY` | a chave real (a mesma do `.env` local) |
   | `APP_URL` | ainda não existe: preencha `https://2m-social-ai.onrender.com` (ajuste se o Render der outro nome) |

5. Aplique. O `docker/start.sh` roda `config:cache`, `route:cache` e — se `RUN_MIGRATIONS=true` — **`migrate --force`**; depois assume o papel que `ROLE` mandar.

6. Depois do primeiro deploy, confirme a URL real do serviço e corrija `APP_URL` se ela tiver saído diferente.

## Depois de subir

- **Healthcheck:** `GET /up` (o Render usa; `SpaFallbackTest` garante que o catch-all da SPA não o engole).
- **A IA roda de verdade** (`AI_PROVIDER=anthropic`). Cada geração custa: estratégia ~4 centavos, lote de copy ~6. O teto é `AI_WORKSPACE_MONTHLY_BUDGET_CENTS` (5000 = US$ 50/mês por workspace) — estourou, a API responde **402** e nenhuma execução começa.
- **Para validar a infra sem gastar:** suba com `AI_PROVIDER=mock`, confirme o fluxo, e troque a variável depois. Nenhum código muda.

## Gotchas

- **O banco entra por `DB_URL`, não por `DB_HOST`/`DB_PORT`.** O Laravel aceita a conexão inteira: `config/database.php` lê `DB_URL` (formato `postgres://…`) antes dos campos separados. Verificado contra o Postgres local.
- **Use a string DIRETA do Neon, nunca a `-pooler`.** O pooler é PgBouncer em modo transação: ele não sustenta os prepared statements do PDO nem o `migrate --force` que o `start.sh` roda no boot. O pooler existe para cenário serverless com centenas de conexões efêmeras — aqui é um container FrankenPHP de vida longa, que não precisa dele.
- **Nunca ponha um pinger para "manter a demo acordada".** Parece uma gentileza e é um tiro no pé: com o serviço de pé, o `queue:work` polla o banco sem parar, o compute do Neon nunca suspende, e os **100 CU-hrs/mês** do free (a 0.25 CU no mínimo) acabam em **~17 dias** — aí o banco suspende até o ciclo virar. O Render dormir em 15 min é o que faz o Neon suspender em 5. **O sono dos dois lados é o que mantém isto de graça.**
- **Restaurar um dump do Render no Neon pede `--no-owner --no-privileges`.** O dump carrega o papel `db_2m_social_ai_db_user`, que é do Render e não existe no Neon; sem as flags o restore falha em cascata.
- **O blueprint repete as env vars nos dois serviços de propósito.** O Render não documenta suporte a âncora YAML (`&x` / `*x`), e um blueprint que não carrega não explica bem o porquê.
- **`config:cache` no build não funciona** — em build time não existe `DB_URL` nem `APP_KEY`. Por isso os caches são gerados no `start.sh`, com o ambiente já injetado.
- **O worker roda a mesma imagem**, só com outro comando. Não há um segundo Dockerfile para manter em sincronia — e é por isso que o modo free (supervisor) e o pago (worker separado) saem da mesma build.
- **Nunca use `dockerCommand` no blueprint.** Ele **substitui o ENTRYPOINT** da imagem — foi assim que o primeiro deploy subiu com o banco vazio: `config:cache` e `migrate` nunca rodaram, e o `queue:work` morria em loop com `relation "cache" does not exist`. O papel do container vem da env var **`ROLE`** (`web`, `worker`, ou vazio = servidor+fila juntos), e quem migra é quem tiver **`RUN_MIGRATIONS=true`** — no modo pago, só o web (duas migrações simultâneas seriam corrida).
- **O FrankenPHP precisa perder as *file capabilities*.** O binário vem com `cap_net_bind_service`; o container do Render roda com capabilities restritas, e executar um binário que as tem falha com **`EPERM`** (`exit status 127`, em loop). O Dockerfile faz `setcap -r` — não precisamos delas, já que a `$PORT` do Render é alta.
- **O nome do host ganha um prefixo se começar com número.** O serviço `2m-social-ai` virou **`twom-social-ai.onrender.com`**. Confira a URL real no painel e ponha ela em `APP_URL`.
- **`--tries=1` no worker é de propósito.** O `RunAgentJob` já trata as próprias falhas (marca o `ai_run` como `failed` com o `error_code`). Retentar o job inteiro **chamaria a API da Anthropic de novo** — e cobraria de novo.
- O `.env` nunca foi versionado, e não há nenhum `sk-ant-` no histórico. Mantenha assim: os segredos vivem só no painel do Render.
