#!/usr/bin/env bash
# HTTPS para UI n8n vía subdominio (ej. n8n.exacto.mx → :5678).
#
# Requisitos:
#   - DNS A record: n8n.exacto.mx → IP del VPS
#   - nginx + certbot en el host (como exacto.mx)
#
# Uso:
#   N8N_DOMAIN=n8n.exacto.mx CERTBOT_EMAIL=admin@exacto.mx bash scripts/vps-enable-n8n-https.sh
set -euo pipefail

cd "$(dirname "$0")/.."

N8N_DOMAIN="${N8N_DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
N8N_PORT="${N8N_PORT:-5678}"

if [[ -z "$N8N_DOMAIN" || -z "$CERTBOT_EMAIL" ]]; then
  echo "Uso: N8N_DOMAIN=n8n.exacto.mx CERTBOT_EMAIL=tu@mail.com bash scripts/vps-enable-n8n-https.sh"
  exit 1
fi

echo ">> n8n HTTPS: ${N8N_DOMAIN} → 127.0.0.1:${N8N_PORT}"

apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y nginx certbot python3-certbot-nginx

cat > "/etc/nginx/sites-available/cotizacion-n8n-${N8N_DOMAIN}" << NGINXEOF
server {
    listen 80;
    server_name ${N8N_DOMAIN};

    client_max_body_size 64M;

    location / {
        proxy_pass http://127.0.0.1:${N8N_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300s;
    }
}
NGINXEOF

ln -sf "/etc/nginx/sites-available/cotizacion-n8n-${N8N_DOMAIN}" /etc/nginx/sites-enabled/cotizacion-n8n
nginx -t
systemctl reload nginx

certbot --nginx -d "${N8N_DOMAIN}" --non-interactive --agree-tos -m "${CERTBOT_EMAIL}" --redirect

# Actualizar .env para UI pública de n8n (webhooks internos siguen en http://n8n:5678)
# Asegurar newline final en .env antes de append (evita concatenar líneas)
[[ -f .env ]] && [[ -n "$(tail -c1 .env 2>/dev/null || true)" ]] && echo >> .env

set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" .env 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${val}|" .env
  else
    echo "${key}=${val}" >> .env
  fi
}

set_env N8N_HOST "${N8N_DOMAIN}"
set_env N8N_PROTOCOL https
set_env N8N_WEBHOOK_BASE "https://${N8N_DOMAIN}"
set_env N8N_SECURE_COOKIE true

# Solo n8n: no recrear postgres ni otros servicios
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --no-deps n8n

echo ""
echo "=== n8n HTTPS listo ==="
echo "  UI: https://${N8N_DOMAIN}/"
echo "  Webhooks Laravel (internos): sin cambio → http://n8n:5678/webhook/..."
echo "  Verificar: docker compose ... exec -T api php artisan n8n:verify-lectura"
