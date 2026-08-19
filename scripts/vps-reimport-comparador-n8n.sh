#!/usr/bin/env bash
# Reimporta el workflow Comparador precios en n8n (corrige URLs log.exacto.mx → nginx).
set -euo pipefail
ROOT="${1:-/var/www/cotizacion}"
WF="$ROOT/docs/n8n/comparador-precios.workflow.json"
CONTAINER="${N8N_CONTAINER:-cotizacion_n8n}"

if [[ ! -f "$WF" ]]; then
  echo "No existe $WF" >&2
  exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "Contenedor $CONTAINER no está corriendo" >&2
  exit 1
fi

docker cp "$WF" "$CONTAINER:/tmp/comparador-precios.workflow.json"
# Importa (crea/actualiza). Luego hay que activar en UI si quedó inactivo.
docker exec -u node "$CONTAINER" n8n import:workflow --input=/tmp/comparador-precios.workflow.json || \
  docker exec "$CONTAINER" n8n import:workflow --input=/tmp/comparador-precios.workflow.json

echo "OK: workflow importado en $CONTAINER"
echo "En n8n UI: desactiva/elimina el workflow viejo con log.exacto.mx,"
echo "  activa «Comparador precios» con URLs http://nginx/api/n8n/..."
