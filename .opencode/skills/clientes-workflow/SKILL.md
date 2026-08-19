---
name: clientes-workflow
description: >-
  Gestión de clientes (CRUD, RFC mexicano, búsqueda, cotizaciones del cliente,
  importación masiva desde Excel). Usar cuando la tarea involucre clientes, el
  módulo Clientes de la SPA, ClienteController, ClienteImportController,
  ClientImportService, MexicanRfc o los endpoints /api/clientes.
---

# Clientes — flujo de negocio

## API (Sanctum)

- `GET /api/clientes?q=` — listar con búsqueda (company, rfc, email, contact_name)
- `POST /api/clientes` — crear
- `GET /api/clientes/{id}` — detalle (con stats y última cotización)
- `PUT /api/clientes/{id}` — actualizar
- `DELETE /api/clientes/{id}` — eliminar (409 si tiene cotizaciones)
- `GET /api/clientes/{id}/cotizaciones?limit=&status=` — cotizaciones del cliente
- `GET /api/clientes/import/plantilla` — descargar plantilla Excel
- `POST /api/clientes/import` — importar archivo (xlsx/xls)
- Permisos: `permission:clientes,{view|create|edit|delete|import}`

## Reglas de negocio

1. **RFC**: se valida con `App\Support\MexicanRfc::isValid` (formato mexicano). Debe ser único (`assertUniqueRfc` busca por RFC normalizado; `Client::normalizeRfc` y `whereNormalizedRfc`).
2. **Eliminación**: `Client::withCount('quotes')`; si `quotesCount > 0` → HTTP 409 con `client_has_quotes` (no se elimina). Capturar `QueryException` como fallback.
3. **Serialización**: usar `$client->toApiArray()` / `toApiArray(true)` (con stats); no construir payloads a mano.
4. **Importación**: `ClientImportService` lee la plantilla (`writeTemplateTo`) e `importFromPath` valida líneas; responde resultados (creados/errores). Archivo `mimes:xlsx,xls`, máx 5120 KB.

## Frontend

- Páginas: `frontend/src/pages/clients/` (ClientsPage, ClientFormPage, ClientDetailPage)
- Componentes: `ClientSearchSelect`, `DeleteClientDialog`
- Lib: `frontend/src/lib/clients-api.ts`

## Verificación

- Tests: `ClienteApiTest`, `ClienteCrmTest`, `MexicanRfcTest` (unit).