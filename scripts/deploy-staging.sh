#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
  if [[ -f .env.staging.example ]]; then
    cp .env.staging.example .env
    echo "Creado .env desde .env.staging.example — edítalo antes de continuar en producción."
  else
    echo "Falta .env. Copia .env.staging.example a .env"
    exit 1
  fi
fi

if ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
  echo "Generando APP_KEY..."
  docker compose -f docker-compose.yml -f docker-compose.staging.yml run --rm api php artisan key:generate --force
fi

echo "Construyendo y levantando stack staging..."
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --build

echo "Esperando PostgreSQL..."
sleep 5

docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan db:seed --class=DashboardUsersSeeder --force
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan wholesalers:sync-catalog

echo ""
echo "Staging listo."
echo "  Health: curl -s http://localhost/api/health"
echo "  SPA:    http://localhost/spa/"
echo "  CT:     docker compose -f docker-compose.yml -f docker-compose.staging.yml exec api php artisan wholesalers:test-ct SKU-EJEMPLO"
