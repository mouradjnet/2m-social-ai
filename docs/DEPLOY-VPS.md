# Deploy na VPS (Hostinger, Ubuntu 24.04, Docker Compose)

A hospedagem definitiva. O Render (`DEPLOY.md`) continua funcionando para demonstração, mas é na VPS que o produto publica no Instagram: o agendador precisa estar acordado às 8h, e o free do Render dorme.

**Regras que este documento não negocia:**

1. **Auditar antes de subir.** A VPS já hospeda o 2M Prev e talvez outros apps. Nada é instalado antes de ler a auditoria.
2. **Nada nas portas 80/443.** Elas são do proxy reverso que já existe. Esta stack publica uma porta só, em `127.0.0.1`.
3. **Nenhuma config existente é sobrescrita.** O domínio desta stack entra no proxy como um vhost **novo**.
4. **Nenhum segredo no repositório.** O `deploy/vps/.env` é ignorado pelo git e pelo Docker.
5. **O domínio vem de variável** (`APP_DOMAIN`). Ainda não há um definitivo; nada aqui inventa um.

---

## Arquitetura

```
Internet ──443──▶ proxy da VPS (JÁ EXISTE; termina o HTTPS, Let's Encrypt)
                      │  vhost novo: APP_DOMAIN → 127.0.0.1:HTTP_PORT
                      ▼
          ┌──────────── stack "2m-social" (rede 2m-social-interna) ────────────┐
          │ nginx :80 ── /storage/* direto do volume `media` (o que a Meta busca)│
          │     └── resto ──▶ app (FrankenPHP :8080, API + SPA, migra no boot)   │
          │ worker     queue:work --queue=publishing,default                     │
          │ scheduler  schedule:work (publications:dispatch a cada minuto,       │
          │            instagram:refresh-tokens às 03:17, batimento a cada min)  │
          │ db         Postgres 16 (volume `pgdata`, sem porta publicada)         │
          │ backup     pg_dump + tar das imagens, 1x/dia, para ./backups          │
          └──────────────────────────────────────────────────────────────────────┘
```

| Serviço | Imagem | Porta | Volume | Saúde | Reinício |
|---|---|---|---|---|---|
| `nginx` | nginx:1.27-alpine | **127.0.0.1:HTTP_PORT** | `media` (ro) | `GET /up` | unless-stopped |
| `app` | 2m-social-ai (Dockerfile da raiz) | — | `media` | `GET /up` | unless-stopped |
| `worker` | a mesma | — | — | o processo (recicla a cada 1 h) | unless-stopped |
| `scheduler` | a mesma | — | — | batimento < 3 min | unless-stopped |
| `db` | postgres:16-alpine | — | `pgdata` | `pg_isready` | unless-stopped |
| `backup` | postgres:16-alpine | — | `./backups`, `media` (ro) | — | unless-stopped |

Logs: `json-file`, 10 MB × 5 arquivos por serviço (o Laravel escreve no stderr). Limites de memória por variável (`*_MEM_LIMIT`).

---

## 1. Auditoria (somente leitura)

Na VPS, com o repositório clonado em `/opt/2m-social-ai` (ou onde preferir):

```bash
cd /opt/2m-social-ai
HTTP_PORT=8090 sh deploy/vps/scripts/audit-vps.sh > ~/auditoria-vps-$(date +%F).txt 2>&1
```

O script **não altera nada**. Leia a saída e responda antes de seguir:

| Pergunta | Onde está na saída | Se der errado |
|---|---|---|
| Quem ocupa 80/443? É Nginx do host, container ou outro? | "Portas em escuta", "Proxy reverso" | Define qual caminho do passo 4 usar |
| A `HTTP_PORT` está livre? | "-> porta 8090: livre" | Escolha outra e ponha no `.env` |
| Já existe algo chamado `2m-social*`? | "Colisão de nomes" | Troque o `name:` do compose |
| Sobra memória para ~1,8 GB (limites somados)? | "Memória e disco", "Uso de recursos" | Baixe os `*_MEM_LIMIT` |
| Sobra disco para banco + imagens + 14 dias de backup? | "Memória e disco" | Reduza `BACKUP_RETENTION_DAYS` |
| O domínio escolhido já aparece em algum `server_name`? | "server_name em uso" | Escolha outro subdomínio |

## 2. Configurar

```bash
cd deploy/vps
cp .env.example .env
chmod 600 .env
```

Preencha: `APP_DOMAIN`, `HTTP_PORT`, `APP_KEY` (ver abaixo), `DB_PASSWORD` (`openssl rand -base64 32`), `REGISTRATION_ALLOWED_EMAILS`. Deixe `INSTAGRAM_DRIVER=fake` e `AI_PROVIDER=mock` no primeiro deploy.

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
curl -fsS http://127.0.0.1:${HTTP_PORT:-8090}/up
```

O `app` roda as migrations na primeira subida. Worker e scheduler só sobem depois de o `app` ficar saudável.

## 4. Domínio e HTTPS (no proxy que já existe)

Aponte o DNS de `APP_DOMAIN` (registro A) para o IP da VPS e espere propagar.

**Se o proxy for o Nginx do host** (o caso mais comum):

```bash
sudo cp deploy/vps/nginx/host-vhost.conf.example /etc/nginx/sites-available/2m-social.conf
sudo sed -i "s/__APP_DOMAIN__/SEU.DOMINIO/; s/__HTTP_PORT__/8090/" /etc/nginx/sites-available/2m-social.conf
sudo ln -s /etc/nginx/sites-available/2m-social.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx     # -t ANTES do reload: um erro não derruba o 2M Prev
sudo certbot --nginx -d SEU.DOMINIO              # emite o certificado e cria o bloco 443
```

O `certbot --nginx -d` mexe **só** no arquivo do domínio pedido. A renovação automática já é do timer do certbot do host.

**Outros proxies:**

- *Nginx Proxy Manager / Traefik / Caddy em container:* crie um host/rota novo para `APP_DOMAIN` apontando para `127.0.0.1:HTTP_PORT` (ou `host.docker.internal:HTTP_PORT`), com Let's Encrypt ligado na própria ferramenta. Não mude as rotas existentes.
- *Nenhum proxy (só o 2M Prev escutando direto na 80/443):* **pare aqui e decida com o responsável pelo 2M Prev.** Colocar um proxy na frente dele é mexer em produção de outro app.

## 5. Conferir

- `https://SEU.DOMINIO/up` → 200
- Login com um e-mail da allowlist; criar o workspace e o projeto
- Subir uma imagem na Biblioteca e abrir `https://SEU.DOMINIO/storage/...` num navegador anônimo: **a Meta precisa ver essa URL sem login**
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

Pede que se digite `restaurar`, para `app`/`worker`/`scheduler`/`nginx`, restaura o banco (`pg_restore --clean`) e as imagens, e religa. Exige o **mesmo `APP_KEY`** do backup. **Teste a restauração uma vez** antes de precisar dela (numa cópia da stack com outro `name:` e outra `HTTP_PORT`).

---

## Atualizar a aplicação

```bash
git pull
cd deploy/vps
docker compose build app
docker compose up -d        # recria app/worker/scheduler com a imagem nova; o app migra
```

O worker termina o job em andamento antes de sair; uma publicação interrompida no meio vira `unknown` e é conferida com a Meta antes de qualquer nova tentativa.

## Monitoramento básico

- `sh deploy/vps/scripts/status.sh` — saúde, `/up`, publicações das últimas 24 h, validade dos tokens, último backup.
- `docker compose logs -f --since 1h worker scheduler` — o caminho da publicação.
- A tela **Instagram** do projeto mostra falhas, "aguardando confirmação" e o aviso de token perto de vencer.
- Sugestão (fora do repo): um monitor externo de uptime em `https://SEU.DOMINIO/up`.

---

## Checklist de deploy

- [ ] Auditoria rodada e lida; decisões anotadas (proxy, `HTTP_PORT`, memória)
- [ ] Domínio escolhido; DNS apontado
- [ ] `deploy/vps/.env` preenchido, `chmod 600`; `APP_KEY` e `DB_PASSWORD` guardados fora da VPS
- [ ] `docker compose config --quiet` sem erro; `docker compose build app` ok
- [ ] `docker compose up -d`; todos `healthy`; `/up` 200 na porta local
- [ ] vhost novo no proxy; `nginx -t` ok antes do reload; `certbot --nginx -d` ok
- [ ] 2M Prev continua respondendo normalmente depois do reload
- [ ] `https://DOMINIO/up` 200; login; imagem em `/storage/...` abre em janela anônima
- [ ] Backup manual rodado; arquivo copiado para fora da VPS
- [ ] Restauração testada numa cópia
- [ ] Só então: app da Meta, `INSTAGRAM_DRIVER=graph`, e a conexão do @2msaudefeminina (`docs/PILOTO-2M-SAUDE-FEMININA.md`)
