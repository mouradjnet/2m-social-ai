# Deploy na VPS (Hostinger, Ubuntu 24.04, Docker Compose)

A hospedagem definitiva, em **https://2msocialai.site**. O Render (`DEPLOY.md`) continua funcionando para demonstração, mas é na VPS que o produto publica no Instagram: o agendador precisa estar acordado às 8h, e o free do Render dorme.

**Regras que este documento não negocia:**

1. **Auditar antes de subir.** A VPS já hospeda o 2M Prev e o 2M Social Vendas. Nada é instalado antes de ler a auditoria.
2. **Nada nas portas 80/443, e nenhuma porta publicada.** Elas são do nginx do 2M Prev (container `2m-prev-nginx-1`). Esta stack é alcançada por ele pela rede Docker `2m-prev_internal`.
3. **Nenhuma config existente é sobrescrita.** O domínio desta stack entra no nginx do 2M Prev como um arquivo **novo** de template.
4. **Nenhum segredo no repositório.** O `deploy/vps/.env` é ignorado pelo git e pelo Docker.
5. **Deploy e restart do nginx do 2M Prev só com autorização explícita**, depois de ensaiar a config num nginx descartável.

---

## Arquitetura

Topologia confirmada pela auditoria de 29/09/2026 (a mesma que o 2M Social Vendas já usa em produção):

```
Internet ──443──▶ 2m-prev-nginx-1 (JÁ EXISTE; termina o HTTPS; certbot do 2M Prev)
                      │  template novo: 2msocialai.site → social-ai-web:80
                      │  rede 2m-prev_internal (externa para esta stack)
                      ▼
          ┌──────────── stack "2m-social-ai" (rede 2m-social-ai-interna) ───────┐
          │ nginx :80 ── /storage/* direto do volume `media` (o que a Meta busca)│
          │     └── resto ──▶ app (FrankenPHP :8080, API + SPA, migra no boot)   │
          │ worker     queue:work --queue=publishing,default                     │
          │ scheduler  schedule:work (publications:dispatch a cada minuto,       │
          │            instagram:refresh-tokens às 03:17, batimento a cada min)  │
          │ db         Postgres 16 (volume `pgdata`, sem porta publicada)         │
          │ backup     pg_dump + tar das imagens, 1x/dia, para ./backups          │
          └──────────────────────────────────────────────────────────────────────┘
```

Só o `nginx` da stack entra na `2m-prev_internal` (apelido `social-ai-web`); app, fila e banco ficam na rede interna, invisíveis para os outros apps.

| Serviço | Imagem | Porta | Volume | Saúde | Reinício |
|---|---|---|---|---|---|
| `nginx` | nginx:1.27-alpine | — (rede `2m-prev_internal`) | `media` (ro) | `GET /up` | unless-stopped |
| `app` | 2m-social-ai (Dockerfile da raiz) | — | `media` | `GET /up` | unless-stopped |
| `worker` | a mesma | — | — | o processo (recicla a cada 1 h) | unless-stopped |
| `scheduler` | a mesma | — | — | batimento < 3 min | unless-stopped |
| `db` | postgres:16-alpine | — | `pgdata` | `pg_isready` | unless-stopped |
| `backup` | postgres:16-alpine | — | `./backups`, `media` (ro) | — | unless-stopped |

Logs: `json-file`, 10 MB × 5 arquivos por serviço (o Laravel escreve no stderr). Limites de memória por variável (`*_MEM_LIMIT`).

---

## 1. Auditoria (somente leitura)

Não precisa clonar nada para auditar — o script vai pelo SSH e não grava na VPS:

```bash
ssh root@VPS 'sh -s' < deploy/vps/scripts/audit-vps.sh > auditoria-vps-$(date +%F).txt 2>&1
```

Leia a saída e responda antes de seguir:

| Pergunta | Onde está na saída | Se der errado |
|---|---|---|
| Quem ocupa 80/443? | "Portas em escuta", "containers que publicam 80/443" | Se não for o `2m-prev-nginx-1`, este guia não se aplica: pare |
| A rede `2m-prev_internal` existe? | "Rede do proxy" | Sem ela o compose não sobe |
| Já existe algo chamado `2m-social-ai*` ou o apelido `social-ai-web`? | "Colisão de nomes" | Troque o `name:` / o `aliases` do compose |
| Sobra memória para ~1,8 GB (limites somados)? | "Memória e disco", "Uso de recursos" | Baixe os `*_MEM_LIMIT` |
| Sobra disco para banco + imagens + 14 dias de backup? | "Memória e disco" | Reduza `BACKUP_RETENTION_DAYS` |

**Resultado de 29/09/2026:** 80/443 são do `2m-prev-nginx-1`; `2m-prev_internal` existe; nenhuma colisão (o `2m-social-vendas*` é outro app); 6,4 GB de RAM e 78 GB de disco livres; sem nginx nem certbot no host; ufw libera só 22/80/443.

## 2. Configurar

```bash
git clone https://github.com/mouradjnet/2m-social-ai.git /opt/2m-social-ai
cd /opt/2m-social-ai/deploy/vps
cp .env.example .env
chmod 600 .env
```

Preencha: `APP_KEY` (ver abaixo), `DB_PASSWORD` (`openssl rand -base64 32`), `REGISTRATION_ALLOWED_EMAILS`. `APP_DOMAIN` já vem `2msocialai.site`. Deixe `INSTAGRAM_DRIVER=fake` e `AI_PROVIDER=mock` no primeiro deploy.

```bash
docker compose build app
docker compose run --rm --no-deps app php artisan key:generate --show   # copie para APP_KEY
docker compose config --quiet && echo "compose ok"
```

**Guarde `APP_KEY` e `DB_PASSWORD` fora da VPS** (gerenciador de senhas). Os tokens do Instagram são criptografados com o `APP_KEY`: sem ele, um backup restaura o banco mas não a conexão.

## 3. Subir

```bash
docker compose up -d
docker compose ps                      # todos "healthy" em ~2 min
sh scripts/status.sh                   # /up conferido de dentro do nginx da stack
```

O `app` roda as migrations na primeira subida. Worker e scheduler só sobem depois de o `app` ficar saudável. Até o passo 4, o site existe só dentro do Docker — o 2M Prev não percebe nada.

## 4. Domínio e HTTPS (no nginx do 2M Prev)

**Pré-requisito:** o DNS de `2msocialai.site` **e** de `www.2msocialai.site` (registro A) apontando para o IP da VPS. Conferir com `nslookup 2msocialai.site 8.8.8.8` antes de começar — em 29/09/2026 ainda apontava para `2.57.91.91` (hospedagem da Hostinger), não para a VPS.

A config do nginx do 2M Prev é **template** (`/opt/2m-prev/deploy/nginx/templates/`, processado por `envsubst` na inicialização): arquivo novo só entra com **restart** do container — alguns segundos do 2M Prev e do Social Vendas fora. E um bloco `:443` sem o certificado impede o nginx de subir, **derrubando os outros sites**. Por isso, três etapas, nesta ordem:

- **A.** `cp deploy/vps/nginx/2msocialai-http.conf.template /opt/2m-prev/deploy/nginx/templates/2msocialai.conf.template` → ensaio → `docker restart 2m-prev-nginx-1` → conferir que o 2M Prev e o Social Vendas respondem **e** que `http://2msocialai.site/.well-known/acme-challenge/x` chega ao nginx (404 dele, não da Hostinger).
- **B.** Emitir o certificado com o certbot da pilha do 2M Prev:
  `docker exec 2m-prev-certbot-1 certbot certonly --webroot -w /var/www/certbot -d 2msocialai.site -d www.2msocialai.site --non-interactive --agree-tos --dry-run` — e sem `--dry-run` depois. A renovação é do loop do certbot do 2M Prev.
- **C.** Sobrescrever o **mesmo** arquivo com `deploy/vps/nginx/2msocialai-https.conf.template` (ele traz o bloco `:80` também) → ensaio → restart → conferir os três sites.

**Ensaio antes de cada restart** — um nginx descartável com as mesmas montagens valida a config sem tocar no de produção:

```bash
T=$(mktemp -d); cp /opt/2m-prev/deploy/nginx/templates/* $T/; cp <novo>.conf.template $T/2msocialai.conf.template
docker run --rm --network 2m-prev_internal -e NGINX_ENVSUBST_FILTER=APP_DOMAIN \
  -e APP_DOMAIN=179-197-238-122.sslip.io -v 2m-prev_certbot_certs:/etc/letsencrypt:ro \
  -v 2m-prev_certbot_webroot:/var/www/certbot:ro -v $T:/etc/nginx/templates:ro \
  nginx:1.27-alpine nginx -t
```

O `proxy_pass` do template usa `resolver` + variável: se esta stack estiver parada, **só este site** dá 502 — o nginx do 2M Prev continua subindo.

**Voltar atrás:** apagar `/opt/2m-prev/deploy/nginx/templates/2msocialai.conf.template` e reiniciar o nginx do 2M Prev.

## 5. Conferir

- `https://2msocialai.site/up` → 200; `https://www.2msocialai.site` redireciona para o domínio sem `www`
- 2M Prev e `https://lojas.2mcomprasonline.com.br` continuam respondendo
- Login com um e-mail da allowlist; criar o workspace e o projeto
- Subir uma imagem na Biblioteca e abrir `https://2msocialai.site/storage/...` num navegador anônimo: **a Meta precisa ver essa URL sem login**
- `sh deploy/vps/scripts/status.sh`

---

## Backups

**O que:** `pg_dump` (formato custom) do banco + `tar.gz` das imagens, todo dia às `BACKUP_HOUR` (padrão 04:00, fuso `BACKUP_TZ`), guardados `BACKUP_RETENTION_DAYS` dias em `deploy/vps/backups/`.

**Agora, à mão:** `docker compose exec backup sh /scripts/backup-once.sh`

**O que falta, e é seu:** o backup mora no **mesmo disco** da VPS. Um disco perdido leva os dois. Copie `deploy/vps/backups/` para fora (rclone para um bucket, `rsync` para outra máquina, ou o snapshot da Hostinger) — e guarde o `APP_KEY` junto, em outro lugar.

## Restauração

```bash
cd deploy/vps
sh scripts/restore.sh backups/db/2m-social-AAAAMMDD-HHMM.dump backups/media/media-AAAAMMDD-HHMM.tar.gz
```

Pede que se digite `restaurar`, para `app`/`worker`/`scheduler`/`nginx`, restaura o banco (`pg_restore --clean`) e as imagens, e religa. Exige o **mesmo `APP_KEY`** do backup. **Teste a restauração uma vez** antes de precisar dela (numa cópia da stack com outro `name:` e outro apelido na `2m-prev_internal`, ou sem a rede `edge`).

---

## Atualizar a aplicação

```bash
cd /opt/2m-social-ai && git pull
cd deploy/vps
docker compose build app
docker compose up -d        # recria app/worker/scheduler com a imagem nova; o app migra
```

O worker termina o job em andamento antes de sair; uma publicação interrompida no meio vira `unknown` e é conferida com a Meta antes de qualquer nova tentativa.

## Monitoramento básico

- `sh deploy/vps/scripts/status.sh` — saúde, `/up`, publicações das últimas 24 h, validade dos tokens, último backup.
- `docker compose logs -f --since 1h worker scheduler` — o caminho da publicação.
- A tela **Instagram** do projeto mostra falhas, "aguardando confirmação" e o aviso de token perto de vencer.
- Sugestão (fora do repo): um monitor externo de uptime em `https://2msocialai.site/up`.

---

## Checklist de deploy

- [x] Auditoria rodada e lida (29/09/2026)
- [ ] DNS de `2msocialai.site` e `www` apontado para a VPS
- [ ] `deploy/vps/.env` preenchido, `chmod 600`; `APP_KEY` e `DB_PASSWORD` guardados fora da VPS
- [ ] `docker compose config --quiet` sem erro; `docker compose build app` ok
- [ ] `docker compose up -d`; todos `healthy`; `status.sh` mostra `/up` 200
- [ ] Etapa A (ensaio → restart) e 2M Prev + Social Vendas no ar
- [ ] Etapa B (certificado, `--dry-run` antes)
- [ ] Etapa C (ensaio → restart) e os três sites no ar
- [ ] `https://2msocialai.site/up` 200; login; imagem em `/storage/...` abre em janela anônima
- [ ] Backup manual rodado; arquivo copiado para fora da VPS
- [ ] Restauração testada numa cópia
- [ ] Só então: app da Meta, `INSTAGRAM_DRIVER=graph`, e a conexão do @2msaudefeminina (`docs/PILOTO-2M-SAUDE-FEMININA.md`)
