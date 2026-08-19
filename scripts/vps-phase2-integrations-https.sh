#!/usr/bin/env bash
# Fase 2 VPS: sincronizar Docker, levantar n8n + Docling, HTTPS (opcional con dominio).
#
# Uso (solo integraciones, IP actual):
#   cd /var/www/cotizacion
#   bash scripts/vps-phase2-integrations-https.sh
#
# Con dominio + HTTPS:
#   DOMAIN=cotizacion.midominio.com CERTBOT_EMAIL=admin@midominio.com \
#     bash scripts/vps-phase2-integrations-https.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]] && [[ -f .env.staging ]]; then
  cp .env.staging .env
  echo ">> Creado .env desde .env.staging"
fi

VPS_IP="${VPS_IP:-2.25.78.222}"
DOMAIN="${DOMAIN:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
APP_PORT="${APP_PORT:-8080}"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)

echo "=== Fase 2: integraciones + HTTPS ==="
echo "VPS_IP=$VPS_IP APP_PORT=$APP_PORT DOMAIN=${DOMAIN:-(sin dominio, HTTP)}"

# --- 1) Validar / parchear archivos Docker clave ---
echo ">> Verificando archivos Docker..."

if ! grep -q 'html/spa' frontend/Dockerfile; then
  sed -i 's|COPY --from=build /app/dist /usr/share/nginx/html$|COPY --from=build /app/dist /usr/share/nginx/html/spa|' frontend/Dockerfile
  echo "   Parcheado frontend/Dockerfile → html/spa"
fi

if ! grep -A5 '^  postgres:' docker-compose.staging.yml 2>/dev/null | grep -q cotizacion; then
  python3 << 'PY'
from pathlib import Path
p = Path("docker-compose.staging.yml")
text = p.read_text()
if "postgres:" not in text.split("services:", 1)[1].split("api:", 1)[0]:
    text = text.replace("services:\n", "services:\n  postgres:\n    networks:\n      - cotizacion\n\n", 1)
    p.write_text(text)
    print("   Parcheado postgres en red cotizacion")
PY
fi

dos2unix docker/entrypoint.sh scripts/*.sh 2>/dev/null || true

# --- 2) .env: n8n, docling, URLs ---
echo ">> Configurando .env (n8n + docling)..."

if [[ -n "$DOMAIN" ]]; then
  BASE_URL="https://${DOMAIN}"
  PUBLIC_N8N="https://${DOMAIN}"
  SANCTUM_DOMAIN="$DOMAIN"
else
  BASE_URL="http://${VPS_IP}:${APP_PORT}"
  PUBLIC_N8N="http://${VPS_IP}:5678"
  SANCTUM_DOMAIN="${VPS_IP}:${APP_PORT}"
fi

# Preservar DB_PASSWORD existente
grep -q '^DB_PASSWORD=' .env || echo 'DB_PASSWORD=secret' >> .env

sed -i "s|^APP_PORT=.*|APP_PORT=${APP_PORT}|" .env
sed -i "s|^STAGING_DOMAIN=.*|STAGING_DOMAIN=${SANCTUM_DOMAIN}|" .env
sed -i "s|^APP_URL=.*|APP_URL=${BASE_URL}|" .env
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=${BASE_URL}|" .env
sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${SANCTUM_DOMAIN}|" .env

# Laravel → n8n (red interna Docker)
grep -q '^N8N_BASE_URL=' .env && sed -i 's|^N8N_BASE_URL=.*|N8N_BASE_URL=http://n8n:5678|' .env || echo 'N8N_BASE_URL=http://n8n:5678' >> .env
grep -q '^DOCLING_BASE_URL=' .env && sed -i 's|^DOCLING_BASE_URL=.*|DOCLING_BASE_URL=http://docling-serve:5001|' .env || echo 'DOCLING_BASE_URL=http://docling-serve:5001' >> .env

# Webhooks n8n (ajustar path tras importar workflows en la UI)
grep -q '^N8N_WEBHOOK_URL=' .env && sed -i 's|^N8N_WEBHOOK_URL=.*|N8N_WEBHOOK_URL=http://n8n:5678/webhook/lectura-docling|' .env || echo 'N8N_WEBHOOK_URL=http://n8n:5678/webhook/lectura-docling' >> .env
grep -q '^N8N_COMPARATOR_WEBHOOK_URL=' .env || echo 'N8N_COMPARATOR_WEBHOOK_URL=http://n8n:5678/webhook/comparador-precios' >> .env

grep -q '^N8N_WEBHOOK_BASE=' .env && sed -i "s|^N8N_WEBHOOK_BASE=.*|N8N_WEBHOOK_BASE=${PUBLIC_N8N}|" .env || echo "N8N_WEBHOOK_BASE=${PUBLIC_N8N}" >> .env
grep -q '^N8N_HOST=' .env && sed -i "s|^N8N_HOST=.*|N8N_HOST=${VPS_IP}|" .env || echo "N8N_HOST=${VPS_IP}" >> .env

if ! grep -q '^N8N_WEBHOOK_SECRET=.\+' .env 2>/dev/null; then
  SECRET="$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)"
  grep -q '^N8N_WEBHOOK_SECRET=' .env && sed -i "s|^N8N_WEBHOOK_SECRET=.*|N8N_WEBHOOK_SECRET=${SECRET}|" .env || echo "N8N_WEBHOOK_SECRET=${SECRET}" >> .env
  echo "   Generado N8N_WEBHOOK_SECRET"
fi

if [[ -n "$DOMAIN" ]]; then
  sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|' .env
else
  sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|' .env
fi

if [[ -n "$DOMAIN" ]]; then
  echo ">> Ajustando workflows n8n (Host → ${DOMAIN})..."
  for wf in docs/n8n/*.workflow.json; do
  [[ -f "$wf" ]] || continue
  sed -i "s/cotizacion\\.test/${DOMAIN}/g" "$wf"
  done
fi

# --- 3) Levantar stack + integraciones ---
  echo ">> Liberando puerto 80 (FRONTEND_PORT no debe ser 80 en staging)..."
  sed -i 's|^FRONTEND_PORT=.*|FRONTEND_PORT=5173|' .env

  echo ">> Levantando stack (incluye n8n + docling)..."
"${COMPOSE[@]}" up -d --build
"${COMPOSE[@]}" --profile integrations up -d n8n docling-serve

echo ">> Esperando servicios (docling puede tardar)..."
sleep 25

"${COMPOSE[@]}" restart api queue scheduler

echo ">> Estado:"
"${COMPOSE[@]}" ps

echo ">> Health:"
curl -fsS "http://127.0.0.1:${APP_PORT}/api/health" | head -c 500 || echo "(health falló — revisar logs api)"

# --- 4) HTTPS con dominio (nginx host + certbot) ---
if [[ -n "$DOMAIN" ]]; then
  if [[ -z "$CERTBOT_EMAIL" ]]; then
    echo "ERROR: para HTTPS definí CERTBOT_EMAIL= tu@correo.com"
    exit 1
  fi

  echo ">> Instalando nginx + certbot en el host..."
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y nginx certbot python3-certbot-nginx

  cat > "/etc/nginx/sites-available/cotizacion-${DOMAIN}" << NGINXEOF
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

    client_max_body_size 64M;

    location / {
        proxy_pass http://127.0.0.1:${APP_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300s;
    }
}
NGINXEOF

  ln -sf "/etc/nginx/sites-available/cotizacion-${DOMAIN}" /etc/nginx/sites-enabled/cotizacion-staging
  rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
  nginx -t
  systemctl reload nginx

  echo ">> Certificado Let's Encrypt..."
  certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --non-interactive --agree-tos -m "${CERTBOT_EMAIL}" --redirect

  sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
  sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=https://${DOMAIN}|" .env
  sed -i "s|^STAGING_DOMAIN=.*|STAGING_DOMAIN=${DOMAIN}|" .env
  sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${DOMAIN},www.${DOMAIN}|" .env
  sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${DOMAIN}|" .env
  sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|' .env
  sed -i "s|^N8N_WEBHOOK_BASE=.*|N8N_WEBHOOK_BASE=https://${DOMAIN}|" .env

  "${COMPOSE[@]}" restart api queue scheduler frontend

  echo ">> HTTPS OK: https://${DOMAIN}/spa/"
else
  echo ""
  echo "HTTPS omitido (sin DOMAIN). Let's Encrypt requiere un dominio apuntando a esta VPS."
  echo "Cuando tengas dominio:"
  echo "  DOMAIN=cotizacion.tudominio.com CERTBOT_EMAIL=tu@mail.com bash scripts/vps-phase2-integrations-https.sh"
fi

echo ""
echo "=== Listo ==="
echo "  App:    ${BASE_URL}/spa/"
echo "  n8n UI: http://${VPS_IP}:5678  (abrir puerto 5678 en firewall Hostinger)"
echo "  Docling: solo red interna (docling-serve:5001)"
echo ""
echo "Próximo paso n8n:"
echo "  1. Entrar a n8n → importar docs/n8n/lectura-cotizacion.workflow.json"
echo "  2. Importar docs/n8n/comparador-precios.workflow.json"
echo "  3. Verificar: docker compose ... exec -T api php artisan n8n:verify-lectura"
echo "  4. Verificar: docker compose ... exec -T api php artisan n8n:verify-comparador"
