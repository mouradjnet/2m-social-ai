#!/bin/sh
set -e

# Os caches de config e rota so podem ser gerados com o ambiente ja injetado: em
# build time nao existe DB_URL nem APP_KEY. Sem cache, cada requisicao releria todos
# os arquivos de config.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrar so em quem serve HTTP.
#
#   frankenphp  — modo pago: o web migra; o worker (servico a parte, mesma imagem)
#                 nao migra. Duas migracoes simultaneas na primeira subida seriam
#                 corrida.
#   supervisord — modo free: um servico so, que serve E roda a fila. Ele migra.
#
# Um `queue:work` solto (o worker do modo pago) cai no `else` e nao migra.
case "$1" in
  frankenphp | supervisord)
    php artisan migrate --force
    ;;
esac

exec "$@"
