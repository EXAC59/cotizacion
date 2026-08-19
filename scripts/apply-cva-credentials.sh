#!/usr/bin/env bash
# Aplica credenciales CVA desde scripts/.wholesaler-cva.local al .env local o VPS.
# Uso local:  ./scripts/apply-cva-credentials.sh
# Uso VPS:    ./scripts/apply-cva-credentials.sh --vps

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOCAL_FILE="$ROOT/scripts/.wholesaler-cva.local"
TARGET_VPS=0

for arg in "$@"; do
  case "$arg" in
    --vps|-TargetVps) TARGET_VPS=1 ;;
  esac
done

if [[ ! -f "$LOCAL_FILE" ]]; then
  echo "[ERROR] Crea scripts/.wholesaler-cva.local desde scripts/.wholesaler-cva.local.example" >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a
# shellcheck source=/dev/null
source <(grep -E '^[A-Z0-9_]+=' "$LOCAL_FILE" || true)
set +a

if [[ -z "${WHOLESALER_CVA_USER:-}" || -z "${WHOLESALER_CVA_PASSWORD:-}" ]]; then
  echo "[ERROR] Completa WHOLESALER_CVA_USER y WHOLESALER_CVA_PASSWORD" >&2
  exit 1
fi

WHOLESALER_CVA_BASE_URL="${WHOLESALER_CVA_BASE_URL:-https://apicvaservices.grupocva.com/api/v2}"
WHOLESALER_CVA_TIMEOUT="${WHOLESALER_CVA_TIMEOUT:-20}"

upsert_env() {
  local file="$1" key="$2" value="$3"
  if grep -q "^${key}=" "$file" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

apply_keys() {
  local file="$1"
  upsert_env "$file" WHOLESALER_CVA_USER "$WHOLESALER_CVA_USER"
  upsert_env "$file" WHOLESALER_CVA_PASSWORD "$WHOLESALER_CVA_PASSWORD"
  upsert_env "$file" WHOLESALER_CVA_BASE_URL "$WHOLESALER_CVA_BASE_URL"
  upsert_env "$file" WHOLESALER_CVA_TIMEOUT "$WHOLESALER_CVA_TIMEOUT"
  upsert_env "$file" WHOLESALER_DEMO_OFFERS false
  # Incluir CVA junto a CT si ya hay códigos; si no, CT,CVA
  if grep -q '^WHOLESALER_COMPARE_CODES=' "$file" 2>/dev/null; then
    current="$(grep '^WHOLESALER_COMPARE_CODES=' "$file" | cut -d= -f2- | tr -d '"' | tr -d "'")"
    if [[ -z "$current" ]]; then
      upsert_env "$file" WHOLESALER_COMPARE_CODES "CT,CVA"
    elif [[ "$current" != *CVA* ]]; then
      upsert_env "$file" WHOLESALER_COMPARE_CODES "${current},CVA"
    fi
  else
    upsert_env "$file" WHOLESALER_COMPARE_CODES "CT,CVA"
  fi
}

if [[ "$TARGET_VPS" -eq 1 ]]; then
  DEPLOY_CFG="$ROOT/scripts/.vps-deploy.local"
  if [[ ! -f "$DEPLOY_CFG" ]]; then
    echo "[ERROR] Falta scripts/.vps-deploy.local" >&2
    exit 1
  fi
  # shellcheck disable=SC1090
  # Quitar CR de archivos Windows (.local con CRLF)
  source <(grep -E '^VPS_(HOST|PASSWORD|REMOTE_PATH)=' "$DEPLOY_CFG" | tr -d '\r' || true)
  VPS_HOST="${VPS_HOST:-root@2.25.78.222}"
  VPS_HOST="${VPS_HOST//$'\r'/}"
  VPS_REMOTE_PATH="${VPS_REMOTE_PATH:-/var/www/cotizacion}"
  VPS_REMOTE_PATH="${VPS_REMOTE_PATH//$'\r'/}"
  VPS_PASSWORD="${VPS_PASSWORD//$'\r'/}"
  export SSHPASS="${VPS_PASSWORD:?VPS_PASSWORD requerido}"
  SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o PreferredAuthentications=password -o PubkeyAuthentication=no)

  # Enviar vars por SSH sin imprimir password
  remote_script=$(cat <<EOF
cd ${VPS_REMOTE_PATH}
cp -a .env .env.bak-cva-\$(date +%Y%m%d-%H%M) 2>/dev/null || true
upsert() { k="\$1"; v="\$2"; grep -q "^\$k=" .env && sed -i "s|^\$k=.*|\$k=\$v|" .env || echo "\$k=\$v" >> .env; }
upsert WHOLESALER_CVA_USER '${WHOLESALER_CVA_USER}'
upsert WHOLESALER_CVA_PASSWORD '${WHOLESALER_CVA_PASSWORD}'
upsert WHOLESALER_CVA_BASE_URL '${WHOLESALER_CVA_BASE_URL}'
upsert WHOLESALER_CVA_TIMEOUT '${WHOLESALER_CVA_TIMEOUT}'
upsert WHOLESALER_DEMO_OFFERS false
if grep -q '^WHOLESALER_COMPARE_CODES=' .env; then
  cur=\$(grep '^WHOLESALER_COMPARE_CODES=' .env | cut -d= -f2-)
  case "\$cur" in
    *CVA*) ;;
    "") upsert WHOLESALER_COMPARE_CODES CT,CVA ;;
    *) upsert WHOLESALER_COMPARE_CODES "\${cur},CVA" ;;
  esac
else
  upsert WHOLESALER_COMPARE_CODES CT,CVA
fi
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan wholesalers:sync-catalog 2>/dev/null || true
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --no-deps --force-recreate api queue
echo '[OK] CVA credentials applied on VPS'
EOF
)
  sshpass -e ssh "${SSH_OPTS[@]}" "$VPS_HOST" "$remote_script"
else
  ENV_FILE="$ROOT/.env"
  if [[ ! -f "$ENV_FILE" ]]; then
    echo "[ERROR] No existe $ENV_FILE" >&2
    exit 1
  fi
  apply_keys "$ENV_FILE"
  echo "[OK] Credenciales CVA aplicadas en .env local"
fi
