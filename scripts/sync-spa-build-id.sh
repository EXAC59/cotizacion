#!/usr/bin/env bash
# Tras rebuild del contenedor frontend, copia build-id.txt al public/spa del host
# para que SpaBuildHeader (API) coincida con el bundle servido por nginx.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
CID="${1:-cotizacion_frontend}"
DEST="${ROOT}/public/spa/build-id.txt"
docker cp "${CID}:/usr/share/nginx/html/spa/build-id.txt" "$DEST"
echo "build-id sincronizado: $(cat "$DEST")"
