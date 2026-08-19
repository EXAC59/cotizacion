#!/usr/bin/env bash
# Corrige N8N_* en VPS: desde el contenedor api, n8n se alcanza por hostname Docker.
set -euo pipefail
cd /var/www/cotizacion
cp -a .env ".env.bak.n8n.$(date +%Y%m%d%H%M%S)"

fix_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${val}|" .env
  else
    echo "${key}=${val}" >> .env
  fi
}

fix_env N8N_BASE_URL 'http://n8n:5678'
fix_env N8N_WEBHOOK_URL 'http://n8n:5678/webhook/lectura-docling'
fix_env N8N_COMPARATOR_WEBHOOK_URL 'http://n8n:5678/webhook/comparador-precios'
grep -E '^N8N_(BASE_URL|WEBHOOK_URL|COMPARATOR_WEBHOOK_URL)=' .env

# Recreate api para aplicar env de docker-compose (restart no basta).
docker compose up -d --force-recreate --no-deps api
sleep 6
docker compose exec -T api php artisan config:clear
docker compose exec -T api printenv | grep -E '^N8N_(WEBHOOK_URL|COMPARATOR|BASE)' || true
docker compose exec -T api php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "config webhook=".config("n8n.webhook_url").PHP_EOL;
$r = Illuminate\Support\Facades\Http::timeout(5)->get("http://n8n:5678/healthz");
echo "n8n healthz HTTP ".$r->status().PHP_EOL;
'
echo FIX_N8N_ENV_OK
