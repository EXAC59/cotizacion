#!/usr/bin/env bash
# Hardening de seguridad operativo en el VPS:
#  - Genera/activa N8N_WEBHOOK_SECRET
#  - Pasa el secreto a n8n
#  - Parchea workflows activos con header X-N8N-Webhook-Secret
#  - Bind n8n a 127.0.0.1:5678 (UFW deniega público)
#  - Rota contraseñas SPA (admin/compras/ventas)
#
# Uso (root en /var/www/cotizacion):
#   bash scripts/vps-security-harden.sh
#   ROTATE_PASSWORDS=0 bash scripts/vps-security-harden.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DC=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)
ROTATE_PASSWORDS="${ROTATE_PASSWORDS:-1}"
PASSWORDS_FILE="${PASSWORDS_FILE:-/root/exacto-spa-passwords.txt}"

set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" .env 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${val}|" .env
  else
    printf '\n%s=%s\n' "$key" "$val" >> .env
  fi
}

echo "==> 1. N8N_WEBHOOK_SECRET"
SECRET=""
if grep -qE '^N8N_WEBHOOK_SECRET=.+' .env 2>/dev/null; then
  SECRET="$(grep -E '^N8N_WEBHOOK_SECRET=' .env | head -1 | cut -d= -f2-)"
  echo "   ya existía (len=${#SECRET})"
else
  SECRET="$(openssl rand -base64 36 | tr -d '/+=' | head -c 40)"
  set_env N8N_WEBHOOK_SECRET "$SECRET"
  echo "   generado (len=${#SECRET})"
fi
set_env N8N_BLOCK_ENV_ACCESS_IN_NODE false

echo "==> 2. UFW: denegar 5678 público"
if command -v ufw >/dev/null 2>&1; then
  ufw deny 5678/tcp >/dev/null 2>&1 || true
  ufw status | grep -E '5678|Status' || true
fi

echo "==> 3. Recrear n8n (127.0.0.1:5678) + api/queue"
"${DC[@]}" --profile integrations up -d --no-deps --force-recreate n8n
"${DC[@]}" up -d --no-deps --force-recreate api queue
sleep 5

echo "==> 4. Parchear workflows n8n (header secreto)"
python3 <<'PY'
import json, subprocess

HEADER = {"name": "X-N8N-Webhook-Secret", "value": "={{ $env.N8N_WEBHOOK_SECRET }}"}

def patch_nodes(nodes):
    changed = 0
    for node in nodes:
        if node.get("type") != "n8n-nodes-base.httpRequest":
            continue
        params = node.setdefault("parameters", {})
        url = str(params.get("url") or "")
        if "nginx" not in url and "/api/" not in url:
            continue
        params["sendHeaders"] = True
        hp = params.setdefault("headerParameters", {})
        plist = hp.get("parameters") or []
        if any(isinstance(p, dict) and p.get("name") == "X-N8N-Webhook-Secret" for p in plist):
            continue
        hp["parameters"] = list(plist) + [HEADER]
        changed += 1
    return changed

def q(sql: str) -> str:
    return subprocess.check_output(
        ["docker", "exec", "-i", "cotizacion_postgres",
         "psql", "-U", "cotizacion", "-d", "n8n", "-t", "-A", "-c", sql],
        text=True,
    ).strip()

rows = q("SELECT id || E'\\t' || nodes::text FROM workflow_entity")
for line in rows.splitlines():
    if not line.strip():
        continue
    wid, nodes_json = line.split("\t", 1)
    nodes = json.loads(nodes_json)
    n = patch_nodes(nodes)
    if n == 0:
        print(f"   {wid}: ok (sin cambios)")
        continue
    payload = json.dumps(nodes, ensure_ascii=False).replace("'", "''")
    sql = (
        "UPDATE workflow_entity SET nodes = '"
        + payload
        + "'::json, \"updatedAt\" = NOW() WHERE id = '"
        + wid
        + "';"
    )
    subprocess.check_call(
        ["docker", "exec", "-i", "cotizacion_postgres",
         "psql", "-U", "cotizacion", "-d", "n8n", "-v", "ON_ERROR_STOP=1", "-c", sql]
    )
    print(f"   {wid}: patched {n} nodes")
print("   PATCH_HEADERS_OK")
PY
docker restart cotizacion_n8n >/dev/null
sleep 4

echo "==> 5. Verificar secreto API"
"${DC[@]}" exec -T api php artisan optimize:clear >/dev/null || true
code="$(docker exec cotizacion_nginx curl -s -o /tmp/sec1.json -w '%{http_code}' -m 8 \
  -X POST http://127.0.0.1/api/n8n/comparador \
  -H 'Content-Type: application/json' -d '{}' || true)"
echo "   sin secret → HTTP ${code} (esperado 401)"
code_ok="$(docker exec cotizacion_nginx curl -s -o /tmp/sec2.json -w '%{http_code}' -m 8 \
  -X POST http://127.0.0.1/api/n8n/comparador \
  -H 'Content-Type: application/json' \
  -H "X-N8N-Webhook-Secret: ${SECRET}" \
  -d '{}' || true)"
echo "   con secret → HTTP ${code_ok} (no debe ser 401)"
ss -tlnp | grep '127.0.0.1:5678' >/dev/null && echo "   n8n listen 127.0.0.1:5678 OK" || echo "   ADVERTENCIA: n8n no en localhost"

if [[ "$ROTATE_PASSWORDS" == "1" ]]; then
  echo "==> 6. Rotar contraseñas SPA"
  umask 077
  ADMIN_PW="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)"
  COMPRAS_PW="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)"
  VENTAS_PW="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)"
  "${DC[@]}" exec -T api php artisan tinker --execute="
\$pairs = [
  'admin@exacto.mx' => '${ADMIN_PW}',
  'compras@exacto.mx' => '${COMPRAS_PW}',
  'ventas@exacto.mx' => '${VENTAS_PW}',
];
foreach (\$pairs as \$email => \$plain) {
  \$u = \\App\\Models\\User::query()->where('email', \$email)->first();
  if (!\$u) { echo \"missing:{\$email}\\n\"; continue; }
  \$u->password = \$plain;
  \$u->save();
  \$u->tokens()->delete();
  echo \"rotated:{\$email}\\n\";
}
"
  {
    echo "# Exacto SPA — contraseñas rotadas $(date -u +%Y-%m-%dT%H:%MZ)"
    echo "# Guarda esto y borra: rm -f ${PASSWORDS_FILE}"
    echo "admin@exacto.mx ${ADMIN_PW}"
    echo "compras@exacto.mx ${COMPRAS_PW}"
    echo "ventas@exacto.mx ${VENTAS_PW}"
  } > "$PASSWORDS_FILE"
  chmod 600 "$PASSWORDS_FILE"
  echo "   guardadas en ${PASSWORDS_FILE}"
  cat "$PASSWORDS_FILE"
else
  echo "==> 6. Rotación omitida (ROTATE_PASSWORDS=0)"
fi

echo ""
echo "SECURITY_HARDEN_OK"
echo "  - Login SPA: throttle 6/min"
echo "  - n8n UI: https://n8n.exacto.mx/ (localhost + UFW deny)"
