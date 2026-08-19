#!/usr/bin/env bash
# Abre puertos CT Connect en UFW (Hostinger VPS).
# CT publica API en :4000 (sandbox) y :3001 (producción).
# Uso: bash scripts/vps-open-ct-ports.sh
set -euo pipefail

PORTS=(4000 3001)

if command -v ufw >/dev/null 2>&1; then
  for p in "${PORTS[@]}"; do
    ufw allow "${p}/tcp" comment "CT Connect API"
  done
  ufw status | grep -E '4000|3001' || true
  echo "UFW: puertos CT abiertos (entrada)."
else
  echo "UFW no instalado — abre 4000/tcp y 3001/tcp en el firewall de Hostinger."
fi

echo ""
echo "También verifica en hPanel Hostinger → VPS → Firewall:"
echo "  - Permitir TCP 4000 y 3001 (entrada y/o salida según indique CT)"
echo "  - IP 2.25.78.222 registrada en CT Connect (No. cliente + IP fija)"
