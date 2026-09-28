#!/bin/sh
# Um backup completo, agora. Roda DENTRO do container `backup`:
#   docker compose exec backup sh /scripts/backup-once.sh
#
# Duas pecas, porque nenhuma basta sozinha:
#   db/     pg_dump em formato custom (restaura com pg_restore, tabela a tabela)
#   media/  as imagens da biblioteca — o banco aponta para elas, e o historico de
#           publicacoes guarda a URL do que foi ao ar.
set -eu

carimbo=$(date +%Y%m%d-%H%M)
mkdir -p /backups/db /backups/media

pg_dump --format=custom --no-owner --file="/backups/db/2m-social-${carimbo}.dump.partial"
mv "/backups/db/2m-social-${carimbo}.dump.partial" "/backups/db/2m-social-${carimbo}.dump"

tar -czf "/backups/media/media-${carimbo}.tar.gz.partial" -C /media .
mv "/backups/media/media-${carimbo}.tar.gz.partial" "/backups/media/media-${carimbo}.tar.gz"

# O `.partial` + `mv` garante que um backup pela metade (disco cheio, container
# derrubado) nunca parece um backup valido.
find /backups/db /backups/media -name '*.partial' -mmin +60 -delete
find /backups/db -name '*.dump' -mtime +"${BACKUP_RETENTION_DAYS:-14}" -delete
find /backups/media -name '*.tar.gz' -mtime +"${BACKUP_RETENTION_DAYS:-14}" -delete

echo "backup ${carimbo}: $(du -h "/backups/db/2m-social-${carimbo}.dump" | cut -f1) de banco, $(du -h "/backups/media/media-${carimbo}.tar.gz" | cut -f1) de imagens"
