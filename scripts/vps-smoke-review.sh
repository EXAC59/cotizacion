#!/usr/bin/env bash
# Smoke test del estatus automático de revisión en producción.
# Crea una solicitud desechable, genera un token Sanctum temporal, comprueba
# que aparece en el dashboard, abre su detalle (auto-marca revisada), verifica
# que sale del dashboard y limpia todo (fila + token). No toca solicitudes reales.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SSH_KEY=/home/dulce123/.ssh/id_ed25519_exacto_vps
VPS=root@2.25.78.222
REMOTE=/var/www/cotizacion
BASE=https://cotizaciones.exacto.mx
ADMIN_ID=1
EXPECTED_REVIEWER="Administrador Exacto"

# UUID de la solicitud desechable (formato válido).
TEST_ID="bbbbbbbb-cccc-dddd-eeee-ffff0000ffff"

DOCKER="docker compose -f $REMOTE/docker-compose.yml"

echo "== 1. Crear solicitud desechable en BD de producción =="
ssh -i "$SSH_KEY" "$VPS" "$DOCKER exec -T postgres psql -U cotizacion -d cotizacion -c \"
INSERT INTO quote_requests (id, source, status, raw_text, created_at, updated_at)
VALUES ('$TEST_ID', 'text', 'procesada', 'SMOKE TEST revisión', now(), now());
\""

echo "== 2. Generar token Sanctum temporal (tinker) =="
TOKEN=$(ssh -i "$SSH_KEY" "$VPS" "$DOCKER exec -T api php artisan tinker --execute=\"
echo App\\\\Models\\\\User::find($ADMIN_ID)->createToken('smoke-review')->plainTextToken;\"" | tr -d '\r')
echo "   token generado: ${TOKEN:0:12}..."

req() { # $1=path  -> imprime HTTP code y body
  curl -sS -w "\n%{http_code}" "$BASE$1" \
    -H "Accept: application/json" -H "Authorization: Bearer $TOKEN"
}

echo "== 3. Dashboard ANTES de abrir: la solicitud debe estar en 'sin revisar' =="
BEFORE=$(req "/api/dashboard")
BEFORE_CODE=$(echo "$BEFORE" | tail -1)
echo "   HTTP $BEFORE_CODE"
echo "$BEFORE" | sed '$d' > /tmp/smoke-dash-before.json
python3 - "$TEST_ID" <<'PY'
import json, sys
rid = sys.argv[1]
data = json.load(open('/tmp/smoke-dash-before.json'))
pending = [r['id'] for r in data.get('alerts', {}).get('pendingReviewRequests', [])]
print(f"   pendingReviewRequests: {len(pending)} item(s)")
print(f"   contiene la desechable ANTES: {rid in pending}")
open('/tmp/smoke-assert-before.txt', 'w').write('1' if rid in pending else '0')
PY

echo "== 4. Abrir el detalle (auto-marca revisada) =="
DETAIL=$(req "/api/solicitudes/$TEST_ID")
DETAIL_CODE=$(echo "$DETAIL" | tail -1)
echo "   HTTP $DETAIL_CODE"
echo "$DETAIL" | sed '$d' > /tmp/smoke-detail.json
python3 - "$EXPECTED_REVIEWER" <<'PY'
import json, sys
expected = sys.argv[1]
d = json.load(open('/tmp/smoke-detail.json'))
name = d.get('reviewed_by_name')
at = d.get('reviewed_at')
print(f"   reviewed_by_name = {name}")
print(f"   reviewed_at      = {at}")
ok = name == expected and bool(at)
open('/tmp/smoke-assert-detail.txt', 'w').write('1' if ok else '0')
PY

echo "== 5. Dashboard DESPUÉS de abrir: la solicitud debe SALIR de 'sin revisar' =="
AFTER=$(req "/api/dashboard")
AFTER_CODE=$(echo "$AFTER" | tail -1)
echo "   HTTP $AFTER_CODE"
echo "$AFTER" | sed '$d' > /tmp/smoke-dash-after.json
python3 - "$TEST_ID" <<'PY'
import json, sys
rid = sys.argv[1]
data = json.load(open('/tmp/smoke-dash-after.json'))
pending = [r['id'] for r in data.get('alerts', {}).get('pendingReviewRequests', [])]
print(f"   contiene la desechable DESPUÉS: {rid in pending}")
open('/tmp/smoke-assert-after.txt', 'w').write('1' if rid not in pending else '0')
PY

echo "== 6. Confirmar en BD =="
ssh -i "$SSH_KEY" "$VPS" "$DOCKER exec -T postgres psql -U cotizacion -d cotizacion -t -A -c \"
SELECT reviewed_by || ' | ' || COALESCE((SELECT name FROM users WHERE id = reviewed_by), '?') || ' | ' || reviewed_at
FROM quote_requests WHERE id = '$TEST_ID';\"" | tr -d '\r'

echo "== 7. Limpiar (borrar solicitud desechable + revocar token) =="
ssh -i "$SSH_KEY" "$VPS" "$DOCKER exec -T postgres psql -U cotizacion -d cotizacion -c \"
DELETE FROM quote_requests WHERE id = '$TEST_ID';\"" | grep -E "DELETE [0-9]"
ssh -i "$SSH_KEY" "$VPS" "$DOCKER exec -T api php artisan tinker --execute=\"
DB::table('personal_access_tokens')->where('name', 'smoke-review')->delete();\"" > /dev/null 2>&1
rm -f /tmp/smoke-dash-before.json /tmp/smoke-dash-after.json /tmp/smoke-detail.json

echo ""
echo "== RESULTADO =="
B1=$(cat /tmp/smoke-assert-before.txt); D1=$(cat /tmp/smoke-assert-detail.txt); A1=$(cat /tmp/smoke-assert-after.txt)
rm -f /tmp/smoke-assert-before.txt /tmp/smoke-assert-detail.txt /tmp/smoke-assert-after.txt
if [[ "$B1" == "1" && "$D1" == "1" && "$A1" == "1" ]]; then
  echo "SMOKE TEST OK"
  echo "  1) Aparece en dashboard 'Solicitudes sin revisar'  ✔"
  echo "  2) Abrir detalle auto-marca como '$EXPECTED_REVIEWER'  ✔"
  echo "  3) Sale del dashboard tras revisarla  ✔"
else
  echo "SMOKE TEST FALLÓ (before=$B1 detail=$D1 after=$A1)"
  exit 1
fi
