#!/bin/sh
# O processo do container `backup`: dorme ate BACKUP_HOUR e faz um backup por dia.
# Um laco simples em vez de cron: o container nao precisa de cron nem de root.
set -eu

while true; do
  agora=$(date +%s)
  alvo=$(date -d "$(date +%Y-%m-%d) ${BACKUP_HOUR:-04}:00:00" +%s 2>/dev/null || echo 0)
  # BusyBox `date -d` nem sempre entende o formato; sem alvo, espera 24 h e faz.
  if [ "$alvo" -le "$agora" ]; then
    alvo=$((alvo + 86400))
  fi
  [ "$alvo" -gt 86400 ] || alvo=$((agora + 86400))

  echo "proximo backup em $(( (alvo - agora) / 60 )) min"
  sleep $((alvo - agora))

  sh /scripts/backup-once.sh || echo "BACKUP FALHOU em $(date)"
  # O do Neon so roda se configurado, e a falha de um nao impede o outro.
  [ -z "${NEON_BACKUP_URL:-}" ] || sh /scripts/backup-neon.sh || echo "BACKUP NEON FALHOU em $(date)"
done
