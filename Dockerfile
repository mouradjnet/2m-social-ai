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
COPY apps/api/composer.json apps/api/composer.lock ./
# Sem dev: nem PHPUnit nem Pint vao para producao.
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# --- 3. A imagem final --------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4

# pdo_pgsql: o banco e Postgres, e o modelo usa jsonb em quase toda tabela.
# opcache/pcntl: performance e o queue:work.
RUN install-php-extensions pdo_pgsql opcache pcntl intl zip

# supervisor: so o modo FREE o usa (um servico rodando servidor + fila no mesmo
# container, porque o plano free do Render nao tem background worker). No modo pago
# o worker e um servico proprio e o supervisor fica ocioso na imagem.
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf

WORKDIR /app

COPY apps/api/ ./
COPY --from=vendor /api/vendor ./vendor
COPY --from=web /web/dist ./public

# O Caddy do FrankenPHP escreve aqui; o Laravel tambem.
RUN chown -R www-data:www-data storage bootstrap/cache

# O Render injeta $PORT. O FrankenPHP escuta nele.
ENV SERVER_NAME=:8080
EXPOSE 8080

# `--no-dev` ja rodou; aqui so o autoload e os caches de config/rota, que so podem
# ser gerados depois que o .env de producao existe — dai o entrypoint.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8080"]
