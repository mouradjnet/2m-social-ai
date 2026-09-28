#!/bin/sh
# Restaura um backup. Roda NO HOST, de dentro de deploy/vps:
#
#   sh scripts/restore.sh backups/db/2m-social-AAAAMMDD-HHMM.dump backups/media/media-AAAAMMDD-HHMM.tar.gz
#
# DESTRUTIVO: substitui o banco e as imagens atuais. Pede confirmacao, para os
# servicos que escrevem (app, worker, scheduler) e religa no fim. Exige o MESMO
# APP_KEY do momento do backup — sem ele os tokens do Instagram nao decifram e a
# conta precisa ser reconectada.
set -eu

dump=${1:?informe o arquivo .dump}
midia=${2:-}

[ -f "$dump" ] || { echo "nao achei $dump"; exit 1; }
[ -z "$midia" ] || [ -f "$midia" ] || { echo "nao achei $midia"; exit 1; }

printf 'Isto SUBSTITUI o banco%s atual pelo backup. Digite "restaurar" para seguir: ' "${midia:+ e as imagens}"
read -r resposta
[ "$resposta" = "restaurar" ] || { echo "cancelado"; exit 1; }

docker compose stop app worker scheduler nginx

docker compose exec -T backup sh -c 'pg_restore --clean --if-exists --no-owner --dbname="$PGDATABASE"' < "$dump"

if [ -n "$midia" ]; then
  # O volume do projeto se chama <projeto>_media. O servico `backup` o monta so
  # leitura; aqui ele entra gravavel em /restaurar, num container de uma vez so.
  docker compose run --rm -T --no-deps \
    -v "${COMPOSE_PROJECT_NAME:-2m-social}_media:/restaurar" --entrypoint sh backup \
    -c 'find /restaurar -mindepth 1 -delete && tar -xzf - -C /restaurar' < "$midia"
fi

docker compose up -d app worker scheduler nginx
echo "restaurado. Confira: docker compose ps  e  https://\$APP_DOMAIN/up"
