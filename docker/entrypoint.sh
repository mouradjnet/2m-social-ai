#!/bin/sh
set -e

# Os caches de config e rota so podem ser gerados com o ambiente ja injetado: em
# build time nao existe DB_URL nem APP_KEY. Sem cache, cada requisicao releria todos
# os arquivos de config.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Quem migra e quem o blueprint mandar — e nao "quem parece ser o servidor".
#
# Antes isto olhava o comando ($1). Quebrou quando o CMD virou forma shell para
# expandir o $PORT: o `$1` passou a ser `/bin/sh`, e a migracao simplesmente parou
# de rodar, em silencio. Uma variavel explicita nao tem essa fragilidade.
#
# So UM processo pode migrar: no modo pago, duas migracoes simultaneas (web e
# worker) na primeira subida seriam corrida.
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

exec "$@"
