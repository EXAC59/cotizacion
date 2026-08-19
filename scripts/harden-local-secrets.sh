#!/usr/bin/env bash
# Endurece permisos de scripts/*.local (credenciales locales, fuera de git).
# Uso: ./scripts/harden-local-secrets.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

shopt -s nullglob
files=(scripts/.*.local)
if [ ${#files[@]} -eq 0 ]; then
  echo "No hay scripts/.*.local"
  exit 0
fi

for f in "${files[@]}"; do
  chmod 600 "$f"
  echo "chmod 600 $f"
done

echo "Listo. Preferí VPS_SSH_KEY sobre VPS_PASSWORD; no copies estos archivos a chats ni repos."
