# O contexto e a RAIZ do monorepo: a imagem precisa dos dois apps.
#
# O Laravel serve a propria SPA (ver routes/web.php): um dominio so, sem CORS e
# sem segunda origem. Por isso o build do Vite entra em `public/` desta imagem.

# --- 1. O frontend vira arquivos estaticos -----------------------------------
FROM node:24-alpine AS web

# pnpm fixado, e nao via corepack: sem o campo `packageManager` no package.json, o
# corepack abre um prompt e o build trava esperando resposta que nunca vem.
RUN npm install -g pnpm@10

WORKDIR /web
COPY apps/web/package.json apps/web/pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY apps/web/ ./
# `pnpm build` roda `tsc -b && vite build`: um erro de tipo derruba a imagem aqui,
# e nao em producao.
RUN pnpm build

# --- 2. As dependencias PHP ---------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /api
# O codigo INTEIRO, nao so os manifestos: o `--optimize-autoloader` monta o classmap
# a partir de `app/`, e o `post-autoload-dump` do Laravel roda `package:discover`
# (que registra os service providers dos pacotes). Com so o composer.json aqui, o
# autoloader sairia sem as classes do App e o discover nao rodaria.
COPY apps/api/ ./
# Sem dev: nem PHPUnit nem Pint vao para producao.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# --- 3. A imagem final --------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4

# pdo_pgsql: o banco e Postgres, e o modelo usa jsonb em quase toda tabela.
# opcache/pcntl: performance e o queue:work.
RUN install-php-extensions pdo_pgsql opcache pcntl intl zip

# supervisor: so o modo FREE o usa (um servico rodando servidor + fila no mesmo
# container, porque o plano free do Render nao tem background worker). No modo pago
# o worker e um servico proprio e o supervisor fica ocioso na imagem.
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor libcap2-bin \
    && rm -rf /var/lib/apt/lists/*
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf

# O binario do FrankenPHP vem com file capabilities (cap_net_bind_service, para
# escutar em porta baixa). O container do Render roda com capabilities restritas, e
# executar um binario que TEM caps nesse ambiente falha com EPERM — o supervisor
# reportava `couldn't exec frankenphp: EPERM` e `exit status 127`, em loop eterno.
#
# Nao precisamos delas: a porta e a $PORT do Render (alta). Removendo as caps, o
# binario vira um executavel comum e roda.
RUN setcap -r /usr/local/bin/frankenphp

WORKDIR /app

COPY apps/api/ ./
COPY --from=vendor /api/vendor ./vendor
# O `packages.php` que o package:discover gerou no stage anterior mora aqui. Sem
# esta linha ele ficaria para tras, e o Laravel teria de redescobrir os pacotes a
# cada boot.
COPY --from=vendor /api/bootstrap/cache ./bootstrap/cache
COPY --from=web /web/dist ./public

# O Caddy do FrankenPHP escreve aqui; o Laravel tambem.
RUN chown -R www-data:www-data storage bootstrap/cache

# O Render injeta $PORT e roteia SO para ela. Este default so vale fora do Render
# (rodar a imagem na mao). Escutar numa porta fixa em producao faz o painel dizer
# "live" enquanto tudo responde 404 com `x-render-routing: no-server`.
ENV PORT=8080
EXPOSE 8080

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# UM ponto de entrada, e nenhum ENTRYPOINT: o `dockerCommand` do Render substitui o
# ENTRYPOINT do Dockerfile, entao a logica que morasse la (caches, migrate) seria
# pulada em silencio. O papel do container vem da env var ROLE, nao do comando.
CMD ["/usr/local/bin/start.sh"]
