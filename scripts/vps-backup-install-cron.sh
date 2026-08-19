#!/usr/bin/env bash
# Instala cron de backup diario (03:00 America/Mexico_City).
# Uso en VPS: cd /var/www/cotizacion && bash scripts/vps-backup-install-cron.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
SCRIPT="${ROOT}/scripts/vps-backup-daily.sh"

chmod +x "$SCRIPT"
dos2unix "$SCRIPT" 2>/dev/null || true

CRON_LINE="0 3 * * * cd ${ROOT} && bash ${SCRIPT} >> /var/log/cotizacion-backup.log 2>&1"
MARKER="# cotizacion-vps-backup"

(crontab -l 2>/dev/null | grep -v "$MARKER" | grep -v 'vps-backup-daily.sh' || true
 echo "$CRON_LINE $MARKER") | crontab -

echo "Cron instalado (03:00 diario):"
crontab -l | grep cotizacion || true
echo ""
echo "Probar ahora: bash scripts/vps-backup-daily.sh"
