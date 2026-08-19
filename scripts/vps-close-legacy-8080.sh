#!/usr/bin/env bash
# Cierra HTTP legacy público en :8080. Host nginx (80/443) sigue usando 127.0.0.1:8080.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Bind Docker frontend a 127.0.0.1:8080"
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --no-deps --force-recreate frontend

echo "==> UFW: quitar allow 8080 y denegar"
if command -v ufw >/dev/null 2>&1; then
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  ufw deny 8080/tcp >/dev/null 2>&1 || true
  ufw status | grep -E '8080|Status' || true
fi

sleep 3
echo "==> Localhost 8080 (debe 200):"
curl -sI -m 8 http://127.0.0.1:8080/spa/ | head -3 || true
echo "==> HTTPS (debe 200):"
curl -sI -m 8 https://exacto.mx/spa/ | head -3 || true
echo "==> Listen 8080 (debe ser 127.0.0.1):"
ss -tlnp | grep ':8080' || true
echo "CLOSE_8080_OK"
