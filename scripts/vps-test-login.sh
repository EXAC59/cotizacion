#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

COOKIE=/tmp/cotiz-login-test.txt
rm -f "$COOKIE"

echo "=== ENV ==="
grep -E '^(APP_URL|SANCTUM|SESSION|APP_KEY)' .env | sed 's/APP_KEY=.*/APP_KEY=***/'

echo "=== CSRF ==="
curl -sS -c "$COOKIE" -b "$COOKIE" -D /tmp/csrf-headers.txt -o /dev/null \
  https://cotizaciones.exacto.mx/sanctum/csrf-cookie \
  -H "Origin: https://cotizaciones.exacto.mx" -H "Referer: https://cotizaciones.exacto.mx/spa/login"
grep -i set-cookie /tmp/csrf-headers.txt || true

TOKEN=$(grep XSRF-TOKEN "$COOKIE" | awk '{print $7}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))")

echo "=== LOGIN ==="
curl -sS -c "$COOKIE" -b "$COOKIE" -D /tmp/login-headers.txt \
  https://cotizaciones.exacto.mx/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Origin: https://cotizaciones.exacto.mx" -H "Referer: https://cotizaciones.exacto.mx/spa/login" \
  -H "X-XSRF-TOKEN: $TOKEN" \
  -d '{"email":"maria@empresa.com","password":"demo"}'
echo
grep -i set-cookie /tmp/login-headers.txt || true

echo "=== USER ==="
curl -sS -b "$COOKIE" -w "\nHTTP:%{http_code}\n" \
  https://cotizaciones.exacto.mx/api/user \
  -H "Accept: application/json" \
  -H "Origin: https://cotizaciones.exacto.mx" -H "Referer: https://cotizaciones.exacto.mx/spa/"

echo "=== SESSIONS (last 3) ==="
DC=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)
"${DC[@]}" exec -T api php artisan tinker --execute="echo json_encode(\Illuminate\Support\Facades\DB::table('sessions')->orderByDesc('last_activity')->limit(3)->get(['id','user_id','last_activity']));"

echo "=== LOG tail ==="
"${DC[@]}" exec -T api tail -5 storage/logs/laravel.log
