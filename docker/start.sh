#!/bin/sh
set -e

# O UNICO ponto de entrada da imagem, nos dois modos. Quem decide o papel e a env
# var ROLE — nao o comando.
#
# Por que assim: o `dockerCommand` do Render SUBSTITUI o ENTRYPOINT do Dockerfile.
# Com a logica no entrypoint, ela simplesmente nao rodava: sem `config:cache`, sem
# `migrate`, e o primeiro deploy subiu com o banco VAZIO — o queue:work morria em
# loop com "relation cache does not exist". Ninguem avisa; so aparece no log.

# Os caches so podem ser gerados com o ambiente injetado: em build time nao existe
# DB_URL nem APP_KEY.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# So um processo migra. No modo pago, web e worker sobem juntos: duas migracoes
# simultaneas na primeira subida seriam corrida.
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

case "$ROLE" in
  worker)
    # Modo pago: servico proprio, so a fila.
    exec php artisan queue:work --tries=1 --timeout=180
    ;;
  web)
    # Modo pago: servico proprio, so o servidor.
    exec frankenphp php-server --root /app/public --listen ":${PORT:-8080}"
    ;;
  *)
    # Modo free (default): um container, servidor E fila, sob supervisao — o plano
    # free do Render nao tem background worker.
    exec supervisord -c /etc/supervisor/conf.d/app.conf
    ;;
esac
