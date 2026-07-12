#!/bin/sh
set -e

# Os caches de config e rota so podem ser gerados com o ambiente ja injetado: em
# build time nao existe DB_HOST nem APP_KEY. Sem cache, cada requisicao releria
# todos os arquivos de config.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# O worker roda o MESMO container com outro comando (ver render.yaml). Migrar so
# no processo web evita duas migracoes simultaneas na primeira subida.
if [ "$1" = "frankenphp" ]; then
  php artisan migrate --force
fi

exec "$@"
