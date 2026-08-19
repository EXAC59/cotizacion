#!/usr/bin/env bash
# Hardening VPS: swap, logs Docker limitados, logrotate Laravel, crons backup+maintain.
# Idempotente. Ejecutar como root en el VPS:
#   bash scripts/vps-harden.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> 1. Swap 2G (si no hay)"
if swapon --show | grep -q .; then
  echo "   swap ya activo"
  swapon --show
else
  if [[ ! -f /swapfile ]]; then
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
  fi
  swapon /swapfile || true
  if ! grep -q '/swapfile' /etc/fstab; then
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
  fi
  # Preferir RAM un poco más (menos thrashing agresivo)
  sysctl -w vm.swappiness=10 >/dev/null
  if ! grep -q 'vm.swappiness' /etc/sysctl.conf 2>/dev/null; then
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
  fi
  echo "   swap OK"
  free -h | head -3
fi

echo "==> 2. Límite logs Docker (json-file)"
DAEMON_JSON=/etc/docker/daemon.json
NEED_DOCKER_RESTART=0
if [[ -f "$DAEMON_JSON" ]]; then
  if ! grep -q 'max-size' "$DAEMON_JSON"; then
    echo "   advertencia: $DAEMON_JSON existe sin max-size — revisar a mano"
  else
    echo "   daemon.json ya tiene max-size"
  fi
else
  cat > "$DAEMON_JSON" <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
JSON
  NEED_DOCKER_RESTART=1
  echo "   creado $DAEMON_JSON"
fi

if [[ "$NEED_DOCKER_RESTART" -eq 1 ]]; then
  echo "   reiniciando Docker (brief downtime)..."
  systemctl restart docker
  sleep 5
  cd "$ROOT"
  docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d
  echo "   stack arriba de nuevo"
fi

echo "==> 3. logrotate Laravel"
cat > /etc/logrotate.d/cotizacion-laravel <<EOF
${ROOT}/storage/logs/laravel.log {
  weekly
  rotate 8
  missingok
  notifempty
  compress
  delaycompress
  copytruncate
}
EOF
echo "   /etc/logrotate.d/cotizacion-laravel OK"

echo "==> 4. Cron backup diario"
bash "${ROOT}/scripts/vps-backup-install-cron.sh"

echo "==> 5. Cron maintain semanal (domingo 04:15)"
chmod +x "${ROOT}/scripts/vps-maintain.sh"
dos2unix "${ROOT}/scripts/vps-maintain.sh" 2>/dev/null || true
MAINT_LINE="15 4 * * 0 cd ${ROOT} && bash ${ROOT}/scripts/vps-maintain.sh >> /var/log/cotizacion-maintain.log 2>&1"
MARKER="# cotizacion-vps-maintain"
(crontab -l 2>/dev/null | grep -v "$MARKER" | grep -v 'vps-maintain.sh' || true
 echo "$MAINT_LINE $MARKER") | crontab -
echo "   cron maintain instalado"

echo "==> 6. Primera pasada maintain ahora"
bash "${ROOT}/scripts/vps-maintain.sh" || true

echo "==> 7. Firewall UFW — cerrar Postgres/n8n/Docling públicos"
if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null 2>&1 || true
  ufw allow 80/tcp >/dev/null 2>&1 || true
  ufw allow 443/tcp >/dev/null 2>&1 || true
  # APP_PORT solo en 127.0.0.1 (docker-compose.staging.yml); no abrir 8080 público
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  ufw deny 8080/tcp >/dev/null 2>&1 || true
  # Cerrar exposiciones directas de servicios internos
  ufw deny 5432/tcp >/dev/null 2>&1 || true
  ufw deny 5678/tcp >/dev/null 2>&1 || true
  ufw deny 5001/tcp >/dev/null 2>&1 || true
  echo "y" | ufw enable >/dev/null 2>&1 || true
  ufw status numbered | head -40 || true
  echo "   UFW: 22/80/443 allow; 8080/5432/5678/5001 deny"
else
  echo "   ufw no instalado — omitiendo (revisar firewall Hostinger)"
fi

echo "==> 8. Health final"
curl -sS -m 10 http://127.0.0.1:8080/api/health | head -c 200; echo
docker compose -f docker-compose.yml -f docker-compose.staging.yml ps
crontab -l | grep cotizacion || true
echo "HARDEN_OK"
