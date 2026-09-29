#!/bin/sh
# Monitoramento basico, de dentro de deploy/vps: saude dos containers, o /up, e o
# resumo das publicacoes das ultimas 24 h.
#   sh scripts/status.sh
set -u

docker compose ps --format 'table {{.Service}}\t{{.Status}}'

# A stack nao publica porta: o /up e conferido de dentro do nginx dela.
printf '\n/up (nginx da stack): '
docker compose exec -T nginx wget -q -O /dev/null http://127.0.0.1/up && echo 200 || echo "FALHOU"

printf '\nPublicacoes (24 h):\n'
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "
  select status, count(*) from publications
   where scheduled_for > now() - interval '"'"'24 hours'"'"' group by status order by status;"'

printf '\nContas do Instagram:\n'
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "
  select username, status, token_expires_at::date from instagram_accounts where status <> '"'"'disconnected'"'"';"'

printf '\nUltimo backup: '
ls -1t backups/db 2>/dev/null | head -1 || echo "nenhum"
