#!/bin/sh
# Auditoria SOMENTE LEITURA da VPS, antes do primeiro deploy. Nao muda nada:
# so lista. Rode como um usuario com acesso ao Docker e guarde a saida:
#
#   sh deploy/vps/scripts/audit-vps.sh > auditoria-vps-$(date +%F).txt 2>&1
#
# O que ela responde: quem ocupa 80/443 (o proxy que NAO vamos tocar), se a rede
# 2m-prev_internal existe, que containers/volumes/redes ja existem (para nao
# colidir de nome), quanto disco e memoria sobram, e qual e a config do Nginx.

secao() { printf '\n===== %s =====\n' "$1"; }

secao "Sistema"
uname -a; cat /etc/os-release 2>/dev/null | head -3; uptime

secao "Memoria e disco"
free -h; df -h / /var/lib/docker 2>/dev/null

secao "CPU"
nproc

secao "Portas em escuta (quem ocupa 80 e 443)"
ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null

secao "Rede do proxy (a stack entra nela como externa)"
docker network inspect 2m-prev_internal --format '{{.Name}}: existe' 2>/dev/null || echo "2m-prev_internal NAO existe — o compose nao sobe"

secao "Docker"
docker version --format '{{.Server.Version}}' 2>/dev/null
docker compose version 2>/dev/null

secao "Containers (todos)"
docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'

secao "Projetos compose"
docker compose ls -a 2>/dev/null

secao "Volumes"
docker volume ls

secao "Redes"
docker network ls

secao "Uso de recursos agora"
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' 2>/dev/null

secao "Colisao de nomes com esta stack (2m-social-ai*, apelido social-ai-web)"
# O 2M Social Vendas (2m-social-vendas*) mora na mesma VPS: nao e colisao.
docker ps -a --format '{{.Names}}' | grep -i '2m-social-ai' || echo "nenhum container 2m-social-ai"
docker volume ls --format '{{.Name}}' | grep -i '2m-social-ai' || echo "nenhum volume 2m-social-ai"
docker network ls --format '{{.Name}}' | grep -i '2m-social-ai' || echo "nenhuma rede 2m-social-ai"
docker network inspect 2m-prev_internal --format '{{range .Containers}}{{.Name}} {{end}}' 2>/dev/null

secao "Proxy reverso: Nginx do host"
if command -v nginx >/dev/null 2>&1; then
  nginx -v 2>&1
  ls -l /etc/nginx/sites-enabled/ 2>/dev/null
  echo "--- server_name em uso:"
  nginx -T 2>/dev/null | grep -E '^\s*server_name' | sort -u
else
  echo "nginx nao instalado no host (o proxy pode ser um container — ver lista acima)"
fi

secao "Proxy reverso: containers que publicam 80/443"
docker ps --format '{{.Names}}\t{{.Image}}\t{{.Ports}}' | grep -E ':(80|443)->' || echo "nenhum container publica 80/443"

secao "Certbot / Let's Encrypt"
command -v certbot >/dev/null 2>&1 && certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry' || echo "certbot nao encontrado no host"

secao "Firewall"
ufw status 2>/dev/null || echo "ufw indisponivel ou sem permissao"

echo
echo "Auditoria concluida. Nada foi alterado."
