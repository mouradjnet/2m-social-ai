#!/bin/sh
set -e

# O UNICO ponto de entrada da imagem, em todos os modos. Quem decide o papel e a env
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

# Com AI_PROVIDER=anthropic e config errada (sem chave, modelo sem preco, timeout
# que nao cabe no worker), o container nao sobe: `set -e` para aqui. Com o mock,
# passa sempre. Nao chama a API.
php artisan ai:check

# /public/storage -> storage/app/public. As imagens da biblioteca moram num volume
# montado ali, e a Meta as busca por essa URL. Na VPS quem serve /storage e o Nginx
# direto do volume; o link cobre quem roda a imagem sem ele (Render, `docker run`).
php artisan storage:link --force >/dev/null 2>&1 || true

# So um processo migra. Web, worker e scheduler sobem juntos: duas migracoes
# simultaneas na primeira subida seriam corrida.
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

# A fila `publishing` vem primeiro: uma geracao de IA de dois minutos na `default`
# nao pode atrasar o post das 8h. `--max-time` recicla o processo a cada hora
# (memoria, conexoes); o restart do container/supervisor o traz de volta.
QUEUE_CMD="php artisan queue:work --queue=publishing,default --tries=1 --timeout=180 --max-time=3600"

case "$ROLE" in
  worker)
    exec $QUEUE_CMD
    ;;
  scheduler)
    # Sem ele nada acontece sozinho: nem a publicacao na hora marcada
    # (`publications:dispatch`, a cada minuto) nem a renovacao dos tokens.
    exec php artisan schedule:work
    ;;
  web)
    exec frankenphp php-server --root /app/public --listen ":${PORT:-8080}"
    ;;
  *)
    # Modo free do Render (default): um container, servidor + fila + agendador, sob
    # supervisao — o plano free nao tem background worker.
    exec supervisord -c /etc/supervisor/conf.d/app.conf
    ;;
esac
