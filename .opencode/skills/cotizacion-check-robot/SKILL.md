---
name: cotizacion-check-robot
description: >-
  Flujo completo de cotizaciones (CRUD, folio, estados, PDF, envío y bloqueo).
  Usar cuando la tarea involucre cotizaciones, el módulo Cotizaciones de la SPA,
  QuotePersistenceService, QuoteStatusGuard, QuotePdfService o el endpoint
  /api/cotizaciones en Laravel+React.
---

# Cotizaciones — flujo de negocio

## API (Sanctum)

- `GET/POST /api/cotizaciones` — listar / guardar
- `GET /api/cotizaciones/proximo-folio` — folio autogenerado
- `POST /api/cotizaciones/{id}/bloqueo` / `DELETE .../bloqueo` — lock de edición
- `GET /api/cotizaciones/{id}/pdf` — PDF
- `POST /api/cotizaciones/{id}/enviar` — envío por correo
- Permisos: `permission:cotizaciones,{action}` (`view|create|edit|delete|send|approve|edit_margin`)

## Reglas de negocio

1. **Folio**: solo `QuoteFolioGenerator` crea el folio al guardar; **no** regenerar al editar.
2. **Guardado**: la persistencia vive en `App\Services\Quotes\QuotePersistenceService` (update → líneas → ofertas → totales). Los controladores validan, no persisten.
3. **Estados**: transiciones controladas por `QuoteStatusGuard` + `QuoteStatusHistoryService` (historial en `quote_status_events`). No permitir transiciones inválidas.
4. **Bloqueo**: `QuoteLockService` / `useQuoteLock` (front) evitan edición concurrente.
5. **PDF**: `QuotePdfService` genera el PDF de `Quote` con campos de `app_settings` (logo, datos empresa). El usuario recarga con **Ctrl+F5** por caché del SPA.
6. **Búsqueda**: `QuoteSearchScope` busca folios flexibles (`demo-001` → `COT-DEMO-0001`).
7. **JSON**: respuestas en camelCase para la SPA (`clientId`, `partNumber`, `statusHistory`).

## Custom actions

- `permission:cotizaciones,edit_margin` → permite editar márgenes (`QuoteProfitCalculator`).
- `permission:cotizaciones,approve` → aprobar cotización.
- `permission:cotizaciones,send` → envío por correo.

## Archivos clave

- `app/Http/Controllers/Api/CotizacionController.php`
- `app/Services/Quotes/` (QuoteFolioGenerator, QuotePersistenceService, QuoteStatusGuard, QuoteStatusHistoryService, QuotePdfService, QuoteLockService, QuoteProfitCalculator)
- `app/Models/Quote.php`, `QuoteLine.php`, `QuoteLineOffer.php`, `QuoteStatusEvent.php`
- Front: `frontend/src/pages/cotizaciones/` y `frontend/src/components/quotes/`

## Verificación

- Tests: `QuoteFolioGeneratorTest`, `QuoteProfitCalculatorTest`, `QuoteStatusGuardTest`, `QuoteSearchScopeTest`, Feature `Cotizacion*Test`, `QuotePdfTest`, `QuoteLockTest`.