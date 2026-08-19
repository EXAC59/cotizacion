#!/usr/bin/env bash
# Parchea URLs del Comparador (entity + history publicada) a /api/n8n/mayoristas.
set -euo pipefail
WID="${1:-}"
docker exec cotizacion_postgres psql -U cotizacion -d n8n -v ON_ERROR_STOP=1 <<SQL
UPDATE workflow_entity
SET nodes = replace(replace(nodes::text,
  'http://nginx/api/mayoristas?active=1',
  'http://nginx/api/n8n/mayoristas?active=1'),
  '=http://nginx/api/mayoristas/',
  '=http://nginx/api/n8n/mayoristas/')::json,
  "updatedAt" = NOW()
WHERE name ILIKE '%Comparador%';

UPDATE workflow_history
SET nodes = replace(replace(nodes::text,
  'http://nginx/api/mayoristas?active=1',
  'http://nginx/api/n8n/mayoristas?active=1'),
  '=http://nginx/api/mayoristas/',
  '=http://nginx/api/n8n/mayoristas/')::json,
  "updatedAt" = NOW()
WHERE "workflowId" IN (SELECT id FROM workflow_entity WHERE name ILIKE '%Comparador%');
SQL

docker restart cotizacion_n8n
echo "PATCH_OK — reiniciado n8n"
