#!/usr/bin/env bash
# Mantenimiento preventivo VPS Cotización (exacto.mx).
# Seguro para cron semanal: libera cache Docker, rota logs Laravel, verifica salud.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)
LOG_FILE="${MAINT_LOG:-/var/log/cotizacion-maintain.log}"

log() { echo "[$(date -Iseconds)] $*" | tee -a "$LOG_FILE"; }

mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"
log "=== maintain inicio ==="

# 1) Salud HTTP vía proxy SPA
HTTP=$(curl -sS -m 12 -o /tmp/cotizacion-health.json -w '%{http_code}' http://127.0.0.1:8080/api/health || echo 000)
if [[ "$HTTP" != "200" ]]; then
  log "WARN health HTTP=$HTTP — reiniciando nginx+api"
  "${COMPOSE[@]}" restart nginx api queue || true
  sleep 4
  HTTP2=$(curl -sS -m 12 -o /tmp/cotizacion-health.json -w '%{http_code}' http://127.0.0.1:8080/api/health || echo 000)
  log "health after restart HTTP=$HTTP2"
else
  log "health OK (200)"
fi

# 2) Solicitudes atascadas > 30 min → error (evita falsas alertas dashboard)
"${COMPOSE[@]}" exec -T api php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$n=App\Models\QuoteRequest::query()
  ->where("status","procesando")
  ->where("updated_at","<",now()->subMinutes(30))
  ->update([
    "status"=>"error",
    "error_message"=>"Lectura interrumpida (timeout automático de mantenimiento). Reenviá o analizar de nuevo.",
  ]);
echo "stuck_marked=$n\n";
' 2>/dev/null | tee -a "$LOG_FILE" || log "WARN no se pudo marcar atascadas"

# 3) Rotar laravel.log si > 20MB
LARAVEL_LOG="$ROOT/storage/logs/laravel.log"
if [[ -f "$LARAVEL_LOG" ]]; then
  SIZE=$(stat -c%s "$LARAVEL_LOG" 2>/dev/null || echo 0)
  if (( SIZE > 20971520 )); then
    TS=$(date +%Y%m%d)
    mv "$LARAVEL_LOG" "${LARAVEL_LOG}.${TS}.bak"
    touch "$LARAVEL_LOG"
    chmod 666 "$LARAVEL_LOG" 2>/dev/null || true
    find "$ROOT/storage/logs" -name 'laravel.log.*.bak' -mtime +14 -delete 2>/dev/null || true
    log "laravel.log rotado (era $SIZE bytes)"
  else
    log "laravel.log OK ($SIZE bytes)"
  fi
fi

# 4) Liberar build cache Docker (no toca imágenes en uso)
BEFORE=$(docker system df --format '{{.Type}} {{.Size}}' | tr '\n' '; ')
docker builder prune -af --filter "until=168h" >/tmp/docker-builder-prune.txt 2>&1 || true
log "builder prune: $(tail -3 /tmp/docker-builder-prune.txt | tr '\n' ' ')"
log "df before: $BEFORE"

# 5) Disco
df -h / | tee -a "$LOG_FILE"

log "=== maintain fin OK ==="
