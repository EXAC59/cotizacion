#!/usr/bin/env bash
# Backup diario Cotización (BD + .env + storage). Retención configurable.
# Uso manual: bash scripts/vps-backup-daily.sh
# Cron: bash scripts/vps-backup-install-cron.sh
set -euo pipefail

cd "$(dirname "$0")/.."

BACKUP_DIR="${BACKUP_DIR:-/var/backups/cotizacion}"
DB_KEEP_DAYS="${BACKUP_DB_KEEP_DAYS:-7}"
ENV_KEEP_DAYS="${BACKUP_ENV_KEEP_DAYS:-30}"
STORAGE_KEEP_DAYS="${BACKUP_STORAGE_KEEP_DAYS:-7}"
LOG_FILE="${BACKUP_LOG:-/var/log/cotizacion-backup.log}"

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)
TS="$(date +%Y%m%d-%H%M)"

log() {
  echo "[$(date -Iseconds)] $*" | tee -a "$LOG_FILE"
}

mkdir -p "$BACKUP_DIR"
touch "$LOG_FILE"

log "=== Backup inicio ($TS) ==="

if ! "${COMPOSE[@]}" ps postgres 2>/dev/null | grep -q 'Up'; then
  log "ERROR: contenedor postgres no está arriba"
  exit 1
fi

DB_FILE="${BACKUP_DIR}/db-${TS}.dump"
"${COMPOSE[@]}" exec -T postgres pg_dump -U "${DB_USERNAME:-cotizacion}" -Fc "${DB_DATABASE:-cotizacion}" > "$DB_FILE"
log "BD OK: $DB_FILE ($(du -h "$DB_FILE" | cut -f1))"

if [[ -f .env ]]; then
  ENV_FILE="${BACKUP_DIR}/env-${TS}.bak"
  cp .env "$ENV_FILE"
  log ".env OK: $ENV_FILE"
fi

STORAGE_FILE="${BACKUP_DIR}/storage-${TS}.tar.gz"
tar -czf "$STORAGE_FILE" -C . storage/app storage/fixtures 2>/dev/null || tar -czf "$STORAGE_FILE" storage
log "storage OK: $STORAGE_FILE ($(du -h "$STORAGE_FILE" | cut -f1))"

log "Retención: db/storage ${DB_KEEP_DAYS}d, env ${ENV_KEEP_DAYS}d"
find "$BACKUP_DIR" -maxdepth 1 -name 'db-*.dump' -mtime +"$DB_KEEP_DAYS" -delete 2>/dev/null || true
find "$BACKUP_DIR" -maxdepth 1 -name 'storage-*.tar.gz' -mtime +"$STORAGE_KEEP_DAYS" -delete 2>/dev/null || true
find "$BACKUP_DIR" -maxdepth 1 -name 'env-*.bak' -mtime +"$ENV_KEEP_DAYS" -delete 2>/dev/null || true

log "=== Backup fin OK ==="
