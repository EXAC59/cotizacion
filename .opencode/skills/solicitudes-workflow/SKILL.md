---
name: solicitudes-workflow
description: >-
  Flujo de solicitudes (CRUD, lectura de texto/archivos, n8n/Docling,
  interpretación a líneas, cotizaciones asociadas). Usar cuando la tarea
  involucre solicitudes, el módulo Solicitudes de la SPA, SolicitudLecturaService,
  DoclingClient, LecturaInterpretacionService o los endpoints /api/solicitudes.
---

# Solicitudes — flujo de negocio

## API (Sanctum)

- `GET/POST /api/solicitudes` — listar / crear
- `POST /api/solicitudes/lectura` — archivos (n8n/Docling)
- `POST /api/solicitudes/lectura-texto` — texto libre
- `PUT /api/solicitudes/{id}/lineas` — editar líneas
- `GET /api/solicitudes/{id}/cotizaciones` — cotizaciones creadas de la solicitud
- `POST /api/solicitudes/{id}/reprocesar` — reinterpretar
- Permisos: `permission:solicitudes,{view|create|edit}`

## Pipeline de lectura

1. **Entrada**: `SolicitudLecturaService` recibe texto libre (`lectura-texto`) o archivo (`lectura`, vía n8n o directo).
2. **Extracción**: `DoclingMarkdownExtractor` / `DoclingClient` convierten el archivo a markdown/texto.
3. **Interpretación**: `LecturaInterpretacionService` + `InterpretacionResult` convierten el texto en líneas (SKU, cantidad, producto, precio) usando `LecturaLineParser` y `Marcas arrays` (`MarcaResolverService`).
4. **Resultado**: líneas validadas con `SolicitudLineasValidator`; la SPA muestra `RequestLinesPreview` / `RequestLinesEditor` para confirmar antes de guardar.
5. **Flujo**: la solicitud se guada y luego puede generar cotizaciones (`SolicitudCotizacionesTest`, `SolicitudCotizaciones` feature).

## Reglas

- `interpretacion_via` registra el origen (n8n / docling / texto / manual).
- Campos de revisión (`reviewed_*`) y `workflow_status` en `quote_requests` (migración 2026-08-11).
- No romper webhooks n8n: rutas `/n8n/lectura` y `/solicitudes/interpretar` **sin** Sanctum (middleware `n8n.webhook`).
- `SolicitudController::updateLineas` valida `lineas.*.warehouse`, `partNumber`, cantidades.

## Archivos clave

- `app/Services/SolicitudLecturaService.php`, `LecturaInterpretacionService.php`, `Documento` `DoclingClient.php`, `DoclingMarkdownExtractor.php`, `LecturaLineParser.php`, `SolicitudLineasValidator.php`
- `app/Http/Controllers/Api/SolicitudController.php`, `SolicitudLecturaController.php`
- `app/Models/QuoteRequest.php`, `QuoteRequestLine.php`
- `app/Http/Controllers/Api/N8nWebhookController.php`
- Front: `frontend/src/pages/requests/`, `frontend/src/components/requests/`, `frontend/src/lib/solicitudes-api.ts`, `solicitud-lectura.ts`, `parse-request-lines.ts`

## Verificación

- Tests: `LecturaLineParserTest`, `SolicitudLecturaTest`, `SolicitudLecturaValidationTest`, `SolicitudTextStoreTest`, `SolicitudStoreTest`, `SolicitudIndexFilterTest`, `SolicitudRevisionTest`, `SolicitudWorkflowStatusTest`, `SolicitudLineasTest`, `SolicitudCotizacionesTest`, `SolicitudLineasComparatorTest`.