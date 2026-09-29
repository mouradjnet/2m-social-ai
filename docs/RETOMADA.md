# Retomada — setembro de 2026

Diário da retomada que transforma o planejador de conteúdo em publicador para o
Instagram. Cada fase registra o que foi encontrado e o que foi verificado.

---

## Fase 0 — Estado inicial (2026-09-28)

**Repositório:** `main` limpo e sincronizado com `origin/main`, último commit `1cacc2b`.

**Banco local:** o cluster 16 na porta 5433 estava de pé, mas o `pg_ctl status`
dizia o contrário e caiu no meio da primeira execução da suíte. Um `pg_ctl start`
disparado por um shell que depois se encerra leva o servidor junto. Suba-o
desacoplado:

```powershell
Start-Process C:\Users\mysho\bin\pgsql16\bin\pg_ctl.exe -ArgumentList 'start','-D','C:\Users\mysho\pgdata\16','-l','C:\Users\mysho\pgdata\pg16.log' -WindowStyle Hidden
```

**Migrations:** o banco de desenvolvimento estava três migrations atrás
(`add_timezone_to_projects`, `index_one_active_strategy_per_project`,
`add_rewriter_to_ai_runs_agents`). Aplicadas com `php artisan migrate` — só
acrescentam coluna, índice e valor de enum; nenhum dado apagado.

**Suítes:**

| | Resultado |
|---|---|
| PHPUnit (Postgres real) | 309 testes, 782 asserções, verdes (76 s) |
| vitest | 109 testes em 15 arquivos, verdes (o README dizia 88) |
| `tsc -b` | sem erros |
| `pnpm build` | ok |

**Fumaça:** `php artisan serve` responde `/up` 200, a SPA responde nos deep links,
o login devolve 422 com credencial errada, `queue:work --stop-when-empty` roda sem
jobs presos e `failed_jobs` está vazio.

**IA:** `AI_PROVIDER=mock` local. O modelo padrão `claude-opus-4-8` continua
válido e com o mesmo preço do Opus 5. Migrar para `claude-opus-5` muda o
comportamento de thinking (ligado por padrão) e fica como recomendação separada,
fora desta retomada.

**Nada impeditivo encontrado.** A única pendência de ambiente é o Postgres local,
que não é serviço e precisa ser iniciado à mão.

---

## Fase 1 — ADR-13 (2026-09-28)

"A IA propõe, o humano aprova e o sistema publica" — ver
[ARCHITECTURE.md](ARCHITECTURE.md#adr-13--a-ia-propõe-o-humano-aprova-e-o-sistema-publica).
Aprovar passou a exigir `reviewer`+ (antes qualquer editor aprovava, contra o
ADR-01), ganhou `approved_by`/`approved_at` e o `PublishGate` recusa peça cuja
aprovação é mais velha que a última mudança de texto ou imagem.

---

## Fase 2 — Biblioteca de imagens (2026-09-28)

**Onde as imagens moram.** A Meta não recebe o arquivo: ela *busca* a imagem numa
URL pública na hora de publicar. O disco precisa sobreviver a deploys e servir por
HTTPS.

| Opção | A favor | Contra |
|---|---|---|
| **Volume Docker na VPS, servido pelo Nginx** ✅ | Zero custo novo, zero dependência, backup junto do banco | O disco da VPS é o limite; se a VPS cair, a Meta não busca |
| Cloudflare R2 | Sem custo de saída, CDN | Mais uma conta e chave; pacote `league/flysystem-aws-s3-v3` (+ AWS SDK) |
| AWS S3 | Padrão de mercado | Custo de saída; mesma dependência |

**Decidido:** volume persistente na VPS (`MEDIA_DISK=public`). O código só fala com
`Storage::disk(config('media.disk'))`: migrar para R2 é instalar o adaptador S3,
declarar o disco e trocar a variável — nenhuma linha de domínio muda.

**Validação no upload, não na publicação.** JPEG/PNG/WebP até 8 MB, largura ≥ 320 px,
proporção entre 4:5 e 1.91:1. Tudo é reencodado para JPEG (o único formato que a
API aceita para imagem), reduzido a 1440 px de largura e sai sem EXIF. O nome do
arquivo é um uuid — a URL é pública, então não pode ser adivinhável.

**Remoção segura.** Imagem presa a peça aprovada, agendada ou publicada não sai (409).
Presa só a rascunhos, sai e os rascunhos ficam sem imagem. Trocar a imagem de uma
peça grava revisão com o de-para e só vale antes da aprovação.

---

## Fase 3 — Integração oficial com o Instagram (2026-09-28)

Fluxo escolhido: **Instagram API with Instagram Login** — ver ADR-14.

- `InstagramGateway` com dois adaptadores: `GraphInstagramGateway` (Meta) e
  `FakeInstagramGateway` (padrão; conecta e publica sem sair da máquina).
- OAuth: `POST /projects/{id}/instagram:connect` (admin+) devolve a URL da Meta; a
  Meta volta em `GET /api/v1/instagram/callback`, que confere o `state`, troca o
  code, pega o token longo, exige os dois escopos e conta profissional, e grava a
  conta. `DELETE /projects/{id}/instagram` desconecta (apaga o token, guarda a linha).
- Renovação diária às 03:17 (`instagram:refresh-tokens`).
- Toda falha da Meta sai classificada: `transient`, `permanent`, `auth` ou
  `unknown` — este último só no `media_publish`, onde a Meta pode ter publicado
  mesmo sem responder.

---

## Fase 4 — Publicação automática (2026-09-28)

**O ciclo.** O agendador roda `publications:dispatch` a cada minuto. Toda peça do
Instagram agendada cuja hora chegou vira uma linha em `publications` — `pending`,
ou já `failed` com o motivo se alguma porta estiver fechada (sem aprovação, texto
mudado depois dela, sem imagem, sem conta, token vencido, legenda acima de 2200,
horário perdido há mais de 12 h). O `PublishJob` roda na fila `publishing`, só dela.

**Idempotência, em três camadas:**
1. Uma publicação por peça **por horário** (índice único). Rodar o agendador duas vezes não cria duas.
2. Nunca duas publicações **vivas** da mesma peça (índice único parcial).
3. Reivindicação atômica: `UPDATE ... SET status='publishing' WHERE status IN (pending, unknown)`. Dois workers com o mesmo job: um passa, o outro não faz nada.

**Resultado desconhecido não se repete às cegas.** Timeout ou 5xx no
`media_publish` deixa a publicação `unknown`. Dois minutos depois o sistema
pergunta o estado do container: `PUBLISHED` → conclui (acha a mídia pela legenda
entre as recentes), `FINISHED` → a Meta não publicou e é seguro publicar, `EXPIRED`
→ novo container. Worker morto no meio (`publishing` há mais de 10 min) vira
`unknown` e segue o mesmo caminho. Se nada decidir, um reviewer confere o perfil e
decide à mão (`resolve`).

**Falhas.** Transitórias (limite de taxa, cota de 24 h, 5xx antes de publicar)
tentam de novo em 1, 5, 15, 30 e 60 min e depois falham. Permanentes (imagem
recusada) falham na hora. Token recusado falha e marca a conta como `expired`.
Cada conversa com a Meta fica em `publication_attempts`.

**Tentar de novo** = remarcar a peça para agora: abre uma publicação nova e a que
falhou continua no histórico.

**Fuso.** Corrigido um defeito anterior: a hora sem fuso vinda da tela
(`<input datetime-local>`) era lida como UTC e o post remarcado para 18:30 sairia
às 15:30 em São Paulo. Agora é lida no fuso do projeto — o teste que fixava o
comportamento antigo foi corrigido junto.

---

## Fase 5 — Interface administrativa (2026-09-28)

Telas novas, no mesmo design system (tokens do `@theme`, `Card`/`Button`/`Input`):

| Onde | O quê |
|---|---|
| `/projects/{id}/instagram` | Conectar/desconectar (com confirmação em dois cliques), validade da autorização, resultado do OAuth; histórico com indicadores, link do post, aprovador, erro, "Tentar de novo agora" e a decisão humana ("Está no ar"/"Não saiu") para resultado desconhecido |
| `/projects/{id}/library` | Upload com o motivo da recusa, grade com dimensões/tamanho/uso, remoção com confirmação |
| Conteúdo → **Editar/Abrir** | Editor de publicação: título, legenda, CTA, hashtags (contador 30), escolha da imagem e **prévia do post** com contador de 2200 caracteres. Aprovada: somente leitura, mostra quem aprovou e agenda no fuso do projeto |
| Quadro | Coluna **Publicado**, miniatura, "Aprovada por", chip do estado no Instagram; o erro do gesto (403 ao aprovar sem ser revisor, 409 com publicação em andamento) aparece em vez de sumir |
| Conteúdo e Calendário | Alerta de conexão: ausente, vencida ou vencendo em menos de 7 dias |

Backend que a interface pediu: `PATCH /contents/{id}/draft` (edição do texto só antes
da aprovação, com revisão de-para).

**Verificado no navegador** (driver `fake`, usuário descartável num workspace
separado): conectar → aprovar como dono → agendar para dali a 2 min → o
`publications:dispatch` criou a publicação e o `queue:work --queue=publishing,default`
publicou → histórico mostrou "Publicado" com mídia e aprovador. A hora 20:48
digitada foi gravada como `20:48-03`.

Dois defeitos achados nessa verificação e corrigidos: o callback do OAuth devolvia
um `Location` absoluto com o host interno (atrás do proxy do Vite — e seria o mesmo
atrás do Nginx); agora é relativo. E uma publicação que já teve resultado
desconhecido podia virar `failed` se a própria conferência falhasse várias vezes,
o que ofereceria "tentar de novo" sobre um post possivelmente no ar; agora ela fica
`unknown` até um humano decidir.

---

## Fase 6 — Stack da VPS (2026-09-28)

Docker Compose próprio em `deploy/vps/` (app, worker, scheduler, nginx, db, backup),
com healthchecks (o do agendador por batimento), `restart: unless-stopped`, limites
de memória e logs rotacionados. Script de auditoria somente leitura, backup diário
com retenção, restauração com confirmação e `status.sh`. A imagem ganhou os papéis
`worker`/`scheduler` e a fila `publishing` passa na frente da `default`. Guia em
[DEPLOY-VPS.md](DEPLOY-VPS.md). O encaixe no proxy foi refeito na Etapa 1 do
roadmap (abaixo), depois da auditoria real.

---

## Fase 7 — Testes e segurança (2026-09-28)

**Resultado:** PHPUnit **412 testes, verdes** (Postgres real) · vitest **125 testes, verdes** ·
`tsc -b` sem erros · `pnpm build` ok · Pint sem pendências · `composer audit` e
`pnpm audit --prod` sem advisories · varredura de segredos nos arquivos versionados
(chaves Anthropic/Meta/AWS, chaves privadas, `APP_KEY`/senha preenchidos): nada.

**Duas travas novas na suíte:** `Http::preventStrayRequests()` no `TestCase` base
(nenhum teste fala com a rede — uma requisição real seria um post de verdade ou uma
chamada paga) e `INSTAGRAM_DRIVER=fake` fixado no `phpunit.xml`.

| Pedido | Onde está provado |
|---|---|
| Autenticação | `AuthAndWorkspaceTest`, `AuthThrottleTest`, `InstagramConnectionTest` (state de uso único, forjado, vencido) |
| Isolamento entre workspaces | `WorkspaceIsolationTest`; 404 para outro tenant em `AssetTest`, `InstagramConnectionTest`, `PublishingTest`, `ContentDraftTest` |
| Autorização | `RouteAuthorizationTest` — o guardião que reprova rota mutante sem teste (pegou as 4 rotas novas desta retomada); aprovar só `reviewer`+ (`ApprovalTest`); conectar só `admin`+ |
| Publicação simulada | `PublishingTest` (Graph com `Http::fake`) e o driver `fake` de ponta a ponta |
| Falhas da API | `GraphInstagramGatewayTest` (classificação), `PublishingTest` (transitória, permanente, token, cota, container lento) |
| Agendamento | `PublishingTest` (antes da hora, fuso do projeto, atraso máximo), `ContentTransitionTest` (remarcar no fuso certo) |
| Idempotência | `PublishingTest`: agendador repetido, índice de publicação viva, job duplicado, resultado desconhecido conferido e nunca republicado |
| Renovação de tokens | `InstagramConnectionTest`: renova perto de vencer, 190 vira `expired`, falha transitória não derruba, vencido sem chamar a Meta |
| Upload | `AssetTest`: formato, tamanho, largura, proporção, arquivo disfarçado de imagem, remoção protegida |

**Revisão de segurança do que foi escrito:**

| Risco | Tratamento |
|---|---|
| CSRF no OAuth (plantar a conta de outro) | `state` de 32 bytes, uso único, 10 min, amarrado a usuário e projeto; papel reconferido na volta |
| Vazamento de token | Cast `encrypted`; `$hidden`; `access_token=***` em toda mensagem de erro gravada ou logada; token nunca em URL da SPA |
| Publicar o que não foi aprovado | `PublishGate` na criação **e** revalidação no job; aprovação amarrada ao texto por id de revisão |
| Post em dobro | Três camadas de idempotência + resultado desconhecido nunca vira `failed` sozinho |
| Upload malicioso | `finfo` decide o tipo; toda imagem é reencodada (EXIF e qualquer carga somem); nome uuid; Nginx serve `/storage` estático com `nosniff` |
| Redirect aberto | O callback só redireciona para caminhos fixos da SPA, relativos |
| Senha do Instagram | Nunca passa pelo sistema: consentimento na própria Meta |

---

## Fase 8 — Preparação do piloto (2026-09-28)

`php artisan pilot:2m-saude-feminina {workspace} --owner=`: projeto, perfil da marca
com as regras de saúde e beleza, estratégia ativa com 5 pilares (as categorias) e
12 ideias do primeiro mês. Idempotente e conservador (não sobrescreve o que foi
editado na tela), e não aprova, não agenda, não conecta e não publica.

Rodado no banco local, no workspace "2M Negocios" do responsável (projeto #6). O
que não se sabe da marca ficou marcado "A CONFIRMAR". O usuário e os dados
descartáveis usados na verificação da Fase 5 foram apagados.

Guia do piloto — testes com o driver `fake`, app da Meta, conexão e primeira
publicação autorizada: [PILOTO-2M-SAUDE-FEMININA.md](PILOTO-2M-SAUDE-FEMININA.md).

**Suíte final:** PHPUnit 415 testes / 1221 asserções, verdes; vitest 125, verdes.

---

# Roadmap de evolução (a partir de 2026-09-29)

Cinco etapas pedidas pelo responsável: Instagram e infraestrutura → estúdio
inteligente → carrosséis e Reels → multimarcas → resultados. Domínio definitivo:
**https://2msocialai.site**. Conta inicial: @2msaudefeminina.

## Etapa 1 — Instagram e infraestrutura (2026-09-29)

**O que já estava feito (Fases 1–8):** biblioteca de imagens, OAuth com a Meta,
publicação automática depois da aprovação humana, agendamento persistente, worker e
scheduler, histórico de publicações, stack Docker e testes.

**Auditoria real da VPS** (somente leitura, script enviado por SSH, nada gravado lá):
80/443 são do container `2m-prev-nginx-1`; **não há nginx nem certbot no host**; a
rede `2m-prev_internal` existe; o 2M Social Vendas já roda ali com o mesmo encaixe;
6,4 GB de RAM e 78 GB de disco livres; ufw libera 22/80/443.

**O que isso mudou no deploy.** O guia antigo supunha um Nginx no host e uma porta em
`127.0.0.1` — nesta VPS, o proxy é um container e não alcançaria essa porta. Agora:

- a stack se chama `2m-social-ai` (o `2m-social` ficava colado no `2m-social-vendas`);
- não publica porta nenhuma; só o nginx dela entra na `2m-prev_internal`, com o
  apelido `social-ai-web`;
- `deploy/vps/nginx/2msocialai-{http,https}.conf.template` são os arquivos novos
  para o nginx do 2M Prev, em três etapas (A: porta 80 + ACME, B: certificado pelo
  certbot do 2M Prev, C: 443), cada uma ensaiada num nginx descartável antes do
  restart. O `proxy_pass` resolve o nome por requisição: se esta stack cair, só este
  site dá 502 — o nginx do 2M Prev continua subindo;
- `host-vhost.conf.example` saiu (não se aplica); `status.sh` confere o `/up` de
  dentro do nginx da stack; `audit-vps.sh` confere a rede e o apelido.

**Auditoria de ações humanas.** A tabela `activity_logs` existia desde a fundação e
ninguém escrevia nela. Três gestos não deixavam rastro de quem os fez — desconectar
o Instagram, apagar uma imagem e decidir à mão uma publicação de resultado
desconhecido (este só num texto livre da tentativa). Agora os três, mais conectar,
gravam `ActivityLog::record`; `GET /projects/{id}/activity` lista os 50 mais recentes
e a tela Instagram mostra a seção **Atividade**. Migration
`2026_09_29_010000_add_project_id_to_activity_logs` (coluna nullable + índice; a
tabela estava vazia em todo ambiente).

**Bloqueios (dependem do responsável, registrados e não contornados):**

1. **DNS.** `2msocialai.site` e `www` apontam para `2.57.91.91` (hospedagem da
   Hostinger), não para a VPS `179.197.238.122`. Sem isso não há certificado.
2. **Deploy na VPS** e restart do nginx do 2M Prev: só com autorização explícita.
3. **App da Meta** (ID e segredo do Instagram, redirect
   `https://2msocialai.site/api/v1/instagram/callback`) e a primeira publicação real.

## Etapa 2 — Estúdio inteligente (2026-09-29)

**Já existia e foi reaproveitado:** editor de legenda, CTA e hashtags (contador de 30) e a
prévia do post com contador de 2200 — Fase 5. Nada foi recriado.

| Sub-etapa | Commit | O quê |
|---|---|---|
| 2a Custos | `fdbb23e` | `GET /workspaces/{ws}/usage` + tela **/consumo** (gasto × teto, por agente e por projeto). Teto por workspace em `workspaces.monthly_budget_cents`, que **só o operador** muda (`php artisan workspace:budget`) — fora do fillable, sem rota. |
| 2b Imagem por IA | `82f64c9` | ADR-15. `ImageProvider` com `fake` (padrão) e `openai`; `POST /contents/{id}/image:generate`. Passa pelo `ImageProcessor`, entra na biblioteca, só é anexada antes da aprovação. Mesmo orçamento e mesma trava de concorrência (`ai_runs.agent = image`). |
| 2c Plano semanal | `fd49c5d` | 9º agente `planner` → `content_plans`. "Escrever as peças do plano" roda o copywriter com uma peça por horário (`planned_for`, `content_plan_id`); o editor oferece "Usar horário do plano". |
| 2d Reaproveitamento | este commit | 10º agente `repurposer`: `POST /contents/{id}/repurpose:generate {format, channel}` cria uma peça nova em Ideia, ligada à original. |

**Aprovação humana preservada:** imagem gerada não troca a de peça aprovada (relido na
hora de gravar); peça planejada e peça reaproveitada nascem `idea`; `planned_for` é
sugestão e agendar continua exigindo aprovação.

**Migrations:** `2026_09_29_020000` (teto do workspace), `030000` (`image` em
`ai_runs.agent`), `040000` (`planner` + `contents.content_plan_id/planned_for`),
`050000` (`repurposer` + `contents.repurposed_from_id`). Todas só acrescentam.

**Bloqueios:** sem `OPENAI_API_KEY` o gerador de imagem real só roda contra
`Http::fake`; o preço por imagem (`IMAGE_COST_CENTS=5`) é estimativa a conferir na
tabela do provedor. Os agentes novos nunca rodaram com a API real da Anthropic (o
`AI_PROVIDER` fica `mock` local).

## Etapa 3 — Carrosséis e Reels (2026-09-29)

**Limitações validadas antes de escrever código** (documentação da Meta — *Content
Publishing* e *IG User Media* —, consultada em 29/09/2026):

| | Regra da Meta | Onde o sistema confere |
|---|---|---|
| Carrossel | 2 a 10 itens; cada item é um container `is_carousel_item`; o carrossel (`media_type=CAROUSEL`, `children`) leva a legenda; recorte pelo 1º item; **conta como um post** | `PUT /slides` (máx. 10, só imagens do projeto) e `Dispatcher` (2 a 10) |
| Reel | `media_type=REELS` + `video_url`; MP4/MOV com moov no início e sem edit list; H.264/HEVC; AAC; 3 s a 15 min; até 1920 px de largura; até 300 MB; capa JPEG opcional (`cover_url`); `share_to_feed` | `Mp4Inspector` + `VideoRules` no **upload**; `Dispatcher` exige o vídeo |
| Processamento | O container de vídeo fica `IN_PROGRESS`; a Meta recomenda consultar 1×/min | `max_container_polls_video = 30` |
| Cota | 100 posts por 24 h | já conferida antes de cada container (Fase 4) |
| Permissões | `instagram_business_basic` + `instagram_business_content_publish` — as mesmas | nada novo a pedir no app da Meta |

| Sub-etapa | Commit | O quê |
|---|---|---|
| 3a | `70af28c` | Vídeo na biblioteca, conferido no upload, guardado sem reencodar. **Defeito latente corrigido:** o PHP da imagem Docker ficava nos 2 MB padrão (`docker/php-uploads.ini`). |
| 3b | `0869c5f` | `content_slides`, `contents.video_asset_id`, `publications.media_type/media`; publicador cria itens + carrossel ou o container de Reel. Formato não publicável (story, vídeo, artigo...) nasce `failed` com o motivo — antes saía como post de imagem. |
| 3c | este commit | Editor: slides em ordem (↑ ↓ ✕), vídeo do Reel e capa, prévia com vídeo e selo do formato; biblioteca mostra vídeo e duração. |

**Fora desta versão (registrado):** carrossel misto com vídeo; Stories (a API os
aceita, mas não entraram no escopo); legenda por slide (a Meta não tem). O que a
estrutura do arquivo não revela (GOP fechado, bitrate real, 4:2:0) fica com a Meta:
o container volta `ERROR` e o histórico mostra.

**Não verificado de ponta a ponta:** nenhuma publicação real de carrossel ou Reel —
depende do app da Meta e da conta conectada (bloqueio da Etapa 1).

## Etapa 4 — Multimarcas (2026-09-29)

**Já existia:** projeto = marca (perfil, estratégia, biblioteca, conta do Instagram e
publicações próprios), `workspace_id` em toda tabela, 404 para outro tenant,
credenciais por projeto e criptografadas. Todas as rotas novas das Etapas 1–3
nasceram com teste de outro tenant (404) e de papel (403).

**Feito:** seletor de espaço de trabalho (`useCurrentWorkspace`): a tela usava sempre
o primeiro workspace, e quem participava de dois (a agência e um cliente, o próprio e
o do convite) nunca via o segundo. A escolha fica no navegador; a API segue
conferindo o papel. Decisão registrada no **ADR-16**: permissão é por workspace, então
marcas que não podem se enxergar ficam em workspaces separados.

**Adiado com motivo (ADR-16):** papel por projeto (mudança de segurança que merece
fatia e decisão próprias); código de Facebook e de outros canais (sem credenciais nem
App Review, seria código que ninguém exercita — o ADR descreve onde cada peça entra).

**Bloqueio:** integração com Facebook depende do app da Meta do responsável, do fluxo
*Facebook Login for Business* e de App Review.
