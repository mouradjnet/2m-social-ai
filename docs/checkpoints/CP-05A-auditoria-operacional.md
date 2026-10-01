# CP-05A — Auditoria operacional, segurança e preparação para produção

Data: 01/10/2026. Auditoria **não destrutiva**: nenhum arquivo preexistente alterado, nenhuma migration, nenhum deploy, nenhum restart, nenhuma escrita em banco de produção, nenhuma chamada à Anthropic, à OpenAI ou à Meta. A única escrita é este arquivo (não commitado).

## 1. Resumo executivo

- **Código:** os 9 checkpoints existem no Git (10 commits, CP-04A tem dois), local = GitHub = VPS em `c666257`, CI verde nos três últimos commits.
- **VPS (https://2msocialai.site):** confirmada em `c666257`, 41/41 migrations aplicadas, IA em `mock`, Instagram em `fake`, imagem em `fake`, debug desligado. **O banco de produção da VPS está praticamente vazio** (0 peças, 1 workspace, 1 usuário, 1 execução mock): as verificações de integridade editorial lá são verdadeiras por vacuidade, não por uso.
- **Render:** a afirmação "todos estão no Render" foi **verificada só em parte**. O frontend servido pelo Render tem o mesmo bundle (`index-ReL3yVEn.js`) que o build local de `c666257` e que a VPS. O backend do Render e o banco Neon (migrations do CP-04D/04E) **não foram verificados**: não há acesso ao painel do Render nem ao Neon nesta sessão.
- **Testes:** API 614/615 na rodada completa; a falha é o teste intermitente conhecido (`ResultsTest`), verde 3/3 ao repetir. Web: lint (2 avisos antigos), `tsc`, 188/188, build ok.
- **Achados:** 1 P0, 2 P1, 6 P2, 6 P3. O P0 é a **exportação em zip, que não confere a aprovação da versão exata** (só o status). A publicação automática no Instagram está corretamente protegida em três pontos.

## 2. Arquitetura encontrada

| Componente | Tecnologia | Onde roda |
|---|---|---|
| Frontend | React 19 + Vite 8 + TS 6 (`apps/web`) | Embutido na imagem Docker e servido pelo mesmo processo da API, nos dois ambientes |
| Backend | Laravel 13.19, PHP 8.4, FrankenPHP (`apps/api`) | VPS: container `app` (`ROLE=web`). Render: container único sob supervisord |
| Banco | PostgreSQL 16 | VPS: container `db` (sem porta publicada no host). Render: Neon free (Oregon), fora do Render |
| Fila | driver `database`, filas `publishing,default`, `--tries=1 --timeout=180` | VPS: container `worker`. Render: programa `queue` no supervisord |
| Agendador | `schedule:work`: `publications:dispatch` a cada minuto, heartbeat, `instagram:refresh-tokens` 03:17, `instagram:collect-insights` 06:40 | VPS: container `scheduler` (healthcheck pelo heartbeat). Render: programa `scheduler` no supervisord (dorme com o serviço free) |
| Backup | `pg_dump` + tar das imagens, diário 04:00, retenção 14 dias | VPS: container `backup`, no mesmo disco. Neon: **sem rotina** (último dump manual 16/07/2026) |
| Proxy/TLS | nginx próprio da stack atrás do nginx do 2M Prev (rede `2m-prev_internal`) | VPS |
| Externos | Anthropic (SDK `anthropic-ai/sdk` 0.7), OpenAI imagens, Meta Graph v23.0 | Todos desligados na VPS (`mock`/`fake`). No Render: não verificado |
| Deploy | VPS: `git pull` + `docker compose build app` + `up -d` (o `app` migra). Render: push na `main` (blueprint `render.yaml`, free, `RUN_MIGRATIONS=true`) |
| Healthchecks | `/up` (app e nginx), heartbeat do agendador, `pg_isready`. Worker sem healthcheck (por desenho) |

## 3. Estado real dos checkpoints

| CP | Commit | Git/GitHub | VPS | Render (web) | Render (API/Neon) | Doc |
|---|---|---|---|---|---|---|
| CP-01 | `3a035d0` | ✅ | ✅ | ✅ | não verificado | só commit |
| CP-02 | `2e7668e` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-03 | `733e313` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-04 | `8b08fb9` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-04A | `5d36523`, `429c899` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-04B | `958a7cc` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-04C | `f1f0043` | ✅ | ✅ | ✅ | não verificado | ✅ |
| CP-04D | `9fa189d` | ✅ | ✅ (migration aplicada) | ✅ | não verificado | ✅ |
| CP-04E | `c666257` | ✅ | ✅ (3 triggers presentes) | n/a (sem mudança de web) | não verificado | ✅ |

"Render (web) ✅" significa: o HTML servido referencia o mesmo bundle que o build local de `c666257` gera. Isso prova o código do frontend, não o do backend nem o estado do banco.

## 4. Verificações realizadas

**Repositório:** `/c/Users/mysho/2m-social-ai`, branch `main`, árvore limpa, sem stash, `HEAD` = `origin/main` = `git ls-remote` = `c666257`, 140 commits. Ignorados presentes: `apps/api/.env`, `vendor/`, `dist/`, caches, `database.sqlite` (0 bytes). Hook `pre-push` ativo (`.githooks`). Dependências: PHP `^8.3`, Laravel `^13.8`, Sanctum 4, SDK Anthropic fixado em `0.7`.

**VPS (somente leitura via SSH):** `HEAD` `c666257` sem alterações locais; `.env` com permissão `600`. Variáveis conferidas por nome (segredos só como preenchido ou vazio): `APP_KEY`, `DB_PASSWORD` e `REGISTRATION_ALLOWED_EMAILS` preenchidos; `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` e `INSTAGRAM_APP_SECRET` vazios; `AI_PROVIDER=mock`, `IMAGE_PROVIDER=fake`, `INSTAGRAM_DRIVER=fake`. `php artisan about`: production, debug OFF. `ai:check`: "Provedor: mock, configuração ok" (não chama a API). `schedule:list` com as 4 tarefas, `queue:failed` vazio, 9 dumps locais. Os 6 serviços estão de pé e os 4 com healthcheck estão saudáveis. Nenhuma porta do banco publicada no host.

**Banco da VPS (consultas de catálogo com `default_transaction_read_only=on`):**
- Triggers `content_versions_sem_update`, `content_versions_sem_delete` e `content_versions_sem_truncate` presentes.
- Únicos:
  - `(content_id, version)` em versões;
  - `(user_id, request_key)` em decisões;
  - uma publicação viva por peça;
  - `(content_id, scheduled_for)` em publicações;
  - uma estratégia ativa por projeto;
  - um run ativo por projeto e agente;
  - `(workspace_id, user_id)` em membros.
- FKs: `publications.decision_id` é `NO ACTION`, o que impede apagar uma decisão usada por uma publicação. As tabelas editoriais fazem cascade a partir da peça.
- Peças sem a versão atual gravada: 0.
- Contagens: 0 peças, 0 versões, 0 decisões, 0 publicações, 1 run (mock, custo 0).

**IA (código):**
- 11 agentes registrados (`strategist`, `copywriter`, `social_media`, `reviewer`, `designer`, `seo`, `analytics`, `rewriter`, `planner`, `repurposer`, `results`), mais a geração de imagem em job próprio.
- Modelo padrão `claude-opus-4-8`, com preço para `claude-opus-4-8` e `claude-opus-5-5`; sem preço, o `AiConfig` recusa subir.
- `max_tokens` 16000 por agente; trava de entrada estimada em 60000 tokens.
- Tempos: timeout 75 s, conexão 10 s, 1 retry pelo SDK, espera de 429 limitada a 10 s, tudo dentro dos 180 s do worker (conferido pelo `ai:check`).
- Erros classificados (`refused`, `rejected_output`, `provider_failed`, `content_changed`). Rate limit e sobrecarga viram mensagem amigável.
- Custo de toda tentativa gravado em `ai_runs`; teto mensal por workspace e opcional por projeto (402); alerta no log.
- A chave existe só no backend: nada de Anthropic no `apps/web`; o histórico Git só cita `sk-ant-` em texto de documentação.
- A IA nunca publica: os agentes gravam em `contents` e tabelas irmãs, e a publicação é outro domínio (`Domain\Publishing`).

**Publicação (código):** inventário na seção 7, achado P0-01.

**Segurança:**
- Rotas sem autenticação: só `login`, `register` (ambas com throttle de 5/min) e o callback do Instagram. As outras 60 rotas `api` exigem Sanctum; tokens expiram em 7 dias.
- `RouteAuthorizationTest` falha se uma rota que altera dados ficar sem teste de 401/403/404.
- O token da Meta é guardado com cast `encrypted` e fica `hidden`. Nenhum `Log::` com token, prompt ou legenda.
- Varredura dos logs da VPS (48 h) por padrões de segredo: 0 ocorrências.
- CORS e cabeçalhos: achados P3-03 e P2-04.
- Dependências: `composer audit` com 4 avisos (P2-03); `pnpm audit` sem vulnerabilidades.

## 5. Testes e resultados

| Comando | Resultado |
|---|---|
| `vendor/bin/pint --test` | ok |
| `php artisan test` (banco `2m_social_ai_test`, IA mock, Instagram fake, `Http::preventStrayRequests`) | 614/615. Falha: `ResultsTest::test_soma_compara_por_pilar_e_formato...` (ordem). Repetido 3×: 6/6 verde |
| `pnpm lint` | 0 erros, 2 avisos antigos (`only-export-components`) |
| `pnpm exec tsc -b` | ok |
| `pnpm test` | 188/188 |
| `pnpm build` | ok, gera `index-ReL3yVEn.js` / `index-BG4qQ6eN.css` |
| CI GitHub (`c666257`, `9fa189d`, `f1f0043`) | success |

As áreas pedidas (regressão editorial, versionamento, aprovação, concorrência, idempotência) estão na suíte: `ContentVersionsTest`, `ApprovalTest`, `ApprovalFlowTest`, `RejectAndChangesTest`, `PublishingTest`, `RewriteGenerationTest`, `SeoGenerationTest`, `RouteAuthorizationTest`. Concorrência real com dois clientes e `pg_locks` foi feita no CP-04A. Esta auditoria não repetiu esse teste.

## 6. Diferenças entre ambientes

| | Local | GitHub | VPS | Render |
|---|---|---|---|---|
| Commit | `c666257` | `c666257` | `c666257` | web equivalente a `c666257`; API não verificada |
| Migrations | 41 (dev local aplicadas) | — | 41/41 | não verificado (Neon) |
| `AI_PROVIDER` | `mock` | blueprint `render.yaml` declara **`anthropic`** | `mock` | **não verificado** (painel) |
| Instagram / imagem | fake / fake | — | fake / fake | não verificado |
| Dados | dev (projeto 2F AutoShop, piloto) | — | vazio | provavelmente os dados reais do Djair; não verificado |
| Backup | — | — | diário, 4 cópias fora da VPS (E:) | **nenhuma rotina**; último dump manual 16/07/2026 |

## 7. Achados

### P0 — Bloqueante

**P0-01 — Exportação em zip não confere a aprovação da versão exata**
- **Componente:** `apps/api/app/Domain/Export/ContentZip.php:26-36` (`GET /projects/{id}/export`, `ExportController`).
- **Evidência:** a exportação seleciona `whereIn('status', ['approved','scheduled'])` e não chama `Approval::validApproval` nem `PublishGate::approvalRefusal`. O Instagram chama o `PublishGate` três vezes (`Dispatcher::prepare`, `Publisher::publicar` no instante do `media_publish`, e o agendamento). O CP-04 registra que peças aprovadas antes do CP-04 continuam `approved` sem snapshot ("precisam ser aprovadas de novo", `CP-04-aprovacao-humana.md:120`). Elas entram no zip rotuladas como "Aprovado".
- **Impacto:** pela decisão do MVP, a publicação é agendar ou exportar o zip. Uma peça sem aprovação válida da versão atual pode chegar ao cliente como aprovada e ser postada à mão, contornando o vínculo aprovação ↔ versão. Depende de existir peça nesse estado: na VPS não há (0 peças); no Neon, não verificado.
- **Correção proposta:** filtrar o zip por `Approval::validApproval($peca) !== null`, ou listar à parte "aprovação vencida, aprove de novo" sem a legenda; incluir no CSV versão e hash aprovados.
- **Teste de validação:** peça `approved` sem decisão válida (ou com hash divergente) não aparece no zip; peça aprovada na versão atual aparece. Falsificar removendo o filtro.

### P1 — Alto

**P1-01 — Provedor de IA real e chave no Render não verificados; blueprint liga `anthropic`**
- **Componente:** `render.yaml` (`AI_PROVIDER: anthropic`, `ANTHROPIC_API_KEY sync:false`); painel do Render.
- **Evidência:** o blueprint declara `anthropic`. O CP-02 registra "sem chamada real" e a VPS está em `mock`, mas o valor efetivo no painel do Render não está acessível nesta sessão.
- **Impacto:** se o painel tiver `anthropic` com chave, a demo pública faz chamadas pagas (limitadas pelo teto de US$ 50/mês por workspace e pela lista de e-mails de cadastro), o que contradiz o estado "sem chamada real" do CP-02.
- **Correção proposta:** conferir no painel `AI_PROVIDER` e se `ANTHROPIC_API_KEY` está preenchida; alinhar o `render.yaml` ao estado decidido (provavelmente `mock` até a autorização).
- **Teste de validação:** `php artisan ai:check` no shell do Render mostra o provedor; `ai_runs.provider` no Neon só `mock` depois da data de corte.

**P1-02 — Banco Neon (Render) sem backup automatizado**
- **Componente:** Neon `2m-social-ai` / branch `production`.
- **Evidência:** os dumps em `E:\backups\2m-social-ai\` são de 12/07 e 16/07/2026; não existe script ou rotina para o Neon (registrado na própria documentação de backup). A janela de restauração nativa do Neon free não foi verificada.
- **Impacto:** perda de até ~2,5 meses de dados do ambiente Render se o projeto Neon for perdido ou corrompido.
- **Correção proposta:** dump manual agora (receita existente com a URL copiada do painel) e uma rotina periódica (por exemplo, `pg_dump` agendado na VPS para o E:/VPS, com a URL guardada como segredo).
- **Teste de validação:** dump datado de hoje restaurado num banco descartável com contagem de tabelas igual.

### P2 — Médio

**P2-01 — Decisões humanas (aprovar/rejeitar/ajustes) imutáveis só no model**
- **Componente:** `content_decisions`; `app/Models/ContentDecision.php:32-33`.
- **Evidência:** o model lança `LogicException` em update/delete, mas não há trigger. SQL direto ou o cascade (peça, projeto, workspace) alteram ou apagam. Na prática, o cascade a partir da peça já é barrado pelo CP-04E, porque as versões travam o DELETE da peça. Continua aberto o UPDATE por SQL direto.
- **Impacto:** a trilha de auditoria da aprovação pode ser reescrita sem rastro por quem tem acesso ao banco.
- **Correção proposta:** mesmo padrão do CP-04C/04E: triggers `BEFORE UPDATE/DELETE/TRUNCATE`.
- **Teste de validação:** UPDATE, DELETE e TRUNCATE por SQL em `content_decisions` recusados, cada um num savepoint.

**P2-02 — Containers da aplicação rodam como root**
- **Componente:** `Dockerfile` (sem `USER`); `docker/supervisord.conf` (`user=root`, FrankenPHP sem `user=`).
- **Evidência:** `/proc/1/status` mostra Uid 0 no `app` (frankenphp) e no `worker` (php), na VPS.
- **Impacto:** uma falha explorável no PHP ganha root dentro do container (volume de mídia gravável, rede interna com o banco).
- **Correção proposta:** `USER www-data` na imagem (porta 8080 já é não privilegiada) e ajustar as permissões do volume `media`.
- **Teste de validação:** `id -un` = `www-data` nos três containers; upload e `/storage` continuam funcionando.

**P2-03 — Dependências PHP com avisos de segurança**
- **Componente:** `composer.lock`.
- **Evidência:** `composer audit`:
  - `laravel/framework` < 13.30 (XSS na página de debug, CVE-2026-102279);
  - `league/commonmark` ≤ 2.10.1 (dois avisos);
  - `league/flysystem` ≤ 3.35.2 (normalização de caminho, CVE-2026-102601).
- **Impacto:** limitado. O debug está desligado em produção e o código não usa markdown (`Str::markdown` ausente). O flysystem é usado no upload de mídia.
- **Correção proposta:** atualizar os três pacotes e rodar a suíte.
- **Teste de validação:** `composer audit` zerado e suíte verde.

**P2-04 — Sem cabeçalhos de segurança HTTP**
- **Componente:** nginx da stack / resposta do app.
- **Evidência:** `curl -I https://2msocialai.site/` não traz `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options` nem `X-Content-Type-Options`.
- **Impacto:** clickjacking da SPA e ausência de defesa em profundidade contra XSS; o token fica no cliente.
- **Correção proposta:** adicionar no `deploy/vps/nginx/app.conf` (HSTS, nosniff, `frame-ancestors 'none'`, CSP compatível com o Vite).
- **Teste de validação:** `curl -I` mostra os cabeçalhos; telas principais sem erro de CSP. Provar com sonda, não só pelo console.

**P2-05 — Backup da VPS no mesmo disco; cópia externa manual**
- **Componente:** container `backup`.
- **Evidência:** 9 dumps em `deploy/vps/backups` (mesmo disco); 4 copiados para o E: à mão.
- **Impacto:** perda do disco da VPS leva banco e backups juntos, se a cópia manual atrasar. Impacto hoje baixo (produção vazia).
- **Correção proposta:** cópia automática para fora (rclone ou rsync agendado) ou snapshot da Hostinger.
- **Teste de validação:** arquivo de hoje presente no destino externo sem ação manual.

**P2-06 — Fluxo editorial nunca exercido com IA real e dados reais em produção**
- **Componente:** 11 agentes; VPS.
- **Evidência:** produção na VPS vazia; desde o CP-02 nenhuma chamada real (registros de 10/07/2026 cobrem só `strategist` e `copywriter`, com o Guzzle anterior); o SDK 0.7 ignora timeout e retries do Client (contornado no código).
- **Impacto:** qualidade e custo reais dos outros 9 agentes, e o caminho HTTP com o Guzzle atualizado, não comprovados.
- **Correção proposta:** a chamada real autorizada prevista no CP-02, com uma marca de teste e teto baixo.
- **Teste de validação:** um run por agente com `provider=anthropic`, `cost_cents` coerente com o preço e `validate()` aceitando.

### P3 — Baixo

**P3-01 — Teste intermitente `ResultsTest`**
- **Evidência:** falhou 1 vez na rodada completa e passou 3/3 ao repetir. `Performance.php:30` ordena só por `published_at`, sem desempate.
- **Correção:** `->orderByDesc('published_at')->orderByDesc('id')`.
- **Teste de validação:** asserção de ordem com dois posts no mesmo segundo.

**P3-02 — Teto de orçamento verificado antes da execução, sem reserva**
- **Componente:** `Budget::refusal`.
- **Evidência:** o gasto só é gravado ao fim. Runs simultâneos de agentes diferentes (até 11) passam pela checagem antes de gravar custo.
- **Impacto:** estouro do teto em algumas execuções.
- **Correção:** reservar uma estimativa ao criar o run.
- **Teste de validação:** N pedidos simultâneos no limite → no máximo o teto mais uma execução.

**P3-03 — CORS `Access-Control-Allow-Origin: *` na API**
- **Evidência:** preflight de `https://evil.example` para `/api/v1/auth/login` → 204 com `*`.
- **Impacto:** baixo. A autenticação é por Bearer, sem cookie, e a SPA é da mesma origem.
- **Correção:** `config/cors.php` restrito ao domínio.
- **Teste de validação:** preflight de origem estranha sem `Allow-Origin`.

**P3-04 — Tabelas filhas sem `workspace_id`**
- **Evidência:** `content_reviews`, `content_revisions`, `content_seo`, `content_slides`, `content_comments`, `publication_attempts`, `publication_metrics`, `brand_profiles`, `content_plans` etc. não têm a coluna, ao contrário da decisão "workspace_id em toda tabela".
- **Impacto:** o isolamento depende da FK para a tabela-mãe com escopo, que `RouteAuthorizationTest` cobre; consultas novas diretas nessas tabelas exigem cuidado.
- **Correção:** documentar a exceção ou adicionar a coluna.
- **Teste de validação:** teste de isolamento por tabela filha.

**P3-05 — Chave da Anthropic real no `.env` local**
- **Evidência:** `apps/api/.env` contém um valor com formato de chave (não versionado, `check-ignore` ok, provedor local `mock`).
- **Impacto:** exposição se o disco ou a pasta forem copiados (backups, OneDrive).
- **Correção:** manter só onde for usada ou girar a chave após a validação real.
- **Teste de validação:** o arquivo não contém chave quando o provedor é `mock`.

**P3-06 — CP-01 sem documento próprio em `docs/checkpoints/`**
- **Evidência:** a pasta começa no CP-02.
- **Correção:** documento curto a partir do commit `3a035d0`.
- **Teste de validação:** n/a.

### Sem achado (verificado)

- Publicação no Instagram exige aprovação humana válida da versão exata em três pontos:
  - `Dispatcher::prepare`: `PublishGate::refusal`, publicação presa a `decision_id` e `content_version`;
  - `Publisher::publicar`: `publicationRefusal` no instante do `media_publish`, inclusive após reconciliar;
  - retry (`PublicationController::retry`): passa pelo `prepare`.
- Qualquer mudança em campo versionado derruba a aprovação; as publicações duplicadas são barradas pelos índices `publications_one_live_per_content` e `(content_id, scheduled_for)` e pela reivindicação por status.
- A IA não tem caminho de publicação.

## 8. Riscos residuais

- O Render e o Neon só foram verificados pelo HTML público. Tudo que depende do painel está marcado como "não verificado".
- A produção da VPS não tem dados: as garantias editoriais estão provadas por testes, não por uso real.
- Não foram executados: concorrência com dois clientes (feita no CP-04A), restauração de backup da VPS hoje (feita em 29/09 e repetida localmente no CP-04E) e auditoria do nginx do 2M Prev (outro projeto).

## 9. Correções necessárias (ordem sugerida)

1. **P0-01:** filtro de aprovação válida na exportação.
2. **P1-01:** conferir o painel do Render e alinhar o `render.yaml` (ação sua, no painel).
3. **P1-02:** dump do Neon agora e rotina de backup.
4. **P2-01** (triggers em `content_decisions`) e **P2-03** (dependências).
5. **P2-02**, **P2-04**, **P2-05** (endurecimento da infra da VPS).
6. **P3-*** conforme houver espaço.

Nenhuma correção foi aplicada.

## 10. Dependências para o CP-05B

- Sua autorização para as correções, e para quais.
- Acesso ao painel do Render (ou o valor de `AI_PROVIDER` e se a chave está preenchida) e a URL direta do Neon (pela área de transferência, receita existente) para fechar P1-01, P1-02 e a coluna "Render (API/Neon)".
- Decisão sobre a chamada real à IA (P2-06), que é o pendente do CP-02.

## 11. Estado final do Git

`main` em `c666257`, igual ao `origin/main`. Único arquivo novo e não versionado: `docs/checkpoints/CP-05A-auditoria-operacional.md` (este relatório). Nenhum arquivo preexistente alterado; o `pnpm build` regravou `apps/web/dist/`, que é ignorado pelo Git.
