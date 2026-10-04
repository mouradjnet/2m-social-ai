#!/bin/sh
# Backup diario do Neon (banco do Render). Roda DENTRO do container `backup`:
#   docker compose exec backup sh /scripts/backup-neon.sh
#
# O papel e o backup_ro (so leitura). A senha vem separada da URL, em
# NEON_BACKUP_PASSWORD: senha dentro da URL com `@` ou `#` quebra o parse e o
# libpq imprime o pedaco dela na mensagem de erro.
set -eu

: "${NEON_BACKUP_URL:?NEON_BACKUP_URL vazio: backup do Neon desligado}"
: "${NEON_BACKUP_PASSWORD:?NEON_BACKUP_PASSWORD vazio}"
base=/backups/neon
carimbo=$(date +%Y%m%d-%H%M)
mkdir -p "$base/daily" "$base/weekly"

falhar() {
  # Marca de falha com hora e motivo curto, lida pelo status.sh.
  echo "$(date -Iseconds) $1" > "$base/ULTIMA_FALHA"
  echo "BACKUP NEON FALHOU: $1"
  exit 1
}

case "$NEON_BACKUP_URL" in
  *://*:*@*) falhar "NEON_BACKUP_URL com senha: a senha vai em NEON_BACKUP_PASSWORD" ;;
esac

# As variaveis PG* do container apontam para o banco LOCAL da VPS; a URL do Neon
# precisa vencer sozinha, entao elas saem do ambiente deste comando.
env -u PGHOST -u PGPORT -u PGUSER -u PGDATABASE \
    PGPASSWORD="$NEON_BACKUP_PASSWORD" PGCONNECT_TIMEOUT=30 \
  pg_dump --dbname="$NEON_BACKUP_URL" --format=custom --no-owner --no-privileges \
          --file="$base/daily/neon-${carimbo}.dump.partial" 2>"$base/.erro" \
  || falhar "pg_dump: $(tail -1 "$base/.erro")"

# Legivel do comeco ao fim, ou nao conta como backup.
pg_restore --list "$base/daily/neon-${carimbo}.dump.partial" >/dev/null 2>&1 || falhar "pg_restore --list"

mv "$base/daily/neon-${carimbo}.dump.partial" "$base/daily/neon-${carimbo}.dump"

# Domingo: copia para a retencao semanal.
if [ "$(date +%u)" = 7 ]; then
  cp "$base/daily/neon-${carimbo}.dump" "$base/weekly/"
fi

# Retencao: 14 diarios, 8 semanais (56 dias). Parciais velhos somem.
find "$base" -name '*.partial' -mmin +60 -delete
find "$base/daily" -name '*.dump' -mtime +"${NEON_DAILY_DAYS:-14}" -delete
find "$base/weekly" -name '*.dump' -mtime +"${NEON_WEEKLY_DAYS:-56}" -delete

rm -f "$base/ULTIMA_FALHA" "$base/.erro"
date -Iseconds > "$base/ULTIMO_OK"
echo "backup neon ${carimbo}: $(du -h "$base/daily/neon-${carimbo}.dump" | cut -f1)"
