# Reglas canónicas — Avisos y seguimiento comercial

Contrato de producto validado en el prototipo (`demo/recordatorios-ventas/`) para portarlo al sistema real (Laravel + SPA) en el VPS **sin reemplazar** el workflow de cotizaciones.

## A. Capas separadas

| Capa | Qué es | Ejemplos |
|------|--------|----------|
| Workflow | Estado operativo de la cotización | `en_elaboracion`, `pendiente_envio` (Lista/Terminada), `enviada`, `aceptada`, `facturada` |
| Seguimiento comercial | Resultado CRM de ventas | `negociacion`, `ganada`, `perdida` |
| Aviso Compras→Ventas | Notificación puntual | `lista_terminada`, `sin_avance` |

Marcar seguimiento **no** cambia `quotes.status`.

## B. Quién puede avisar (Compras)

Elegible si el seguimiento **no** es `ganada`/`perdida` y cumple **uno**:

| Caso | Condición | `reasonCode` |
|------|-----------|--------------|
| Lista / Terminada | `status = pendiente_envio` | `lista_terminada` |
| Sin avance | `status = en_elaboracion` y días idle ≥ N | `sin_avance` |

- **N** (demo): `3`. En VPS: `AppSetting::resolvedUnansweredQuoteDays()` (default 3 en `config/dashboard.php`).
- Idle en VPS: días desde `last_opened_at` o `updated_at` (mismo criterio que alertas `unansweredQuotes`).

### Validaciones de aviso

1. Rechazar si no elegible (mensaje claro).
2. Destinatario: `quotes.created_by` si es ventas; si no, usuario ventas asignable. Demo: bandeja global.
3. **Dedupe:** no crear otro aviso si ya existe uno **no leído** con el mismo `quoteId` + `reasonCode`.
4. Mensaje opcional; vacío → plantilla por `reasonCode`.

## C. Seguimiento (Ventas)

| Estatus | Obligatorio | Limpia |
|---------|-------------|--------|
| Negociación | `remindAt` con fecha **≥ hoy** | invoice, comments |
| Ganada | `invoice` trim, **2–60** caracteres | comments, remindAt |
| Perdida | `comments` trim, **3–500** caracteres | invoice, remindAt |

### Validaciones de seguimiento

1. Select de estatus obligatorio.
2. Negociación: rechazar fecha pasada.
3. Un solo campo `comments` (no usar `reason`).
4. Al guardar: marcar leídas las notificaciones de esa cotización.
5. Abrir desde campana:
   - Si ya `ganada`/`perdida` → solo detalle (no forzar Negociación).
   - Si sin seguimiento o `negociacion` → abrir en Negociación + fecha.
6. Reagendar: solo si `negociacion` y `remindAt <= hoy`.

## D. Roles UI

| Rol | Acciones |
|-----|----------|
| Compras | Ver candidatas / otras; avisar a ventas |
| Ventas | Campana, dashboard seguimiento, PDF, marcar estatus |

VPS: no depender solo de `dashboard.view` (ventas hoy no lo tiene). Preferir permiso de cotizaciones + endpoint de notificaciones o módulo ligero de inbox.

## Modelo de datos (demo → VPS)

### Demo (`localStorage`)

```
Quote: id, folio, client, workflowStatus, daysIdle, followUp, …
FollowUp: status, invoice, comments, at, remindAt
Notification: id, quoteId, reasonCode, message, createdAt, read
```

### VPS (propuesto, aditivo)

| Artefacto | Uso |
|-----------|-----|
| `quote_follow_ups` o columnas en `quotes` | `follow_up_status`, `follow_up_invoice`, `follow_up_comments`, `follow_up_remind_at`, `follow_up_at` |
| `sales_notifications` | `quote_id`, `sender_id`, `recipient_id`, `reason_code`, `message`, `read_at` |
| API Sanctum | `GET/POST /api/notificaciones`, `PATCH …/leer`, `PATCH /api/cotizaciones/{id}/seguimiento` |

### Archivos reales a tocar (cuando se implemente)

- `config/quotes.php` — **no** añadir estatus CRM al workflow
- `app/Models/Quote.php`, servicios nuevos bajo `app/Services/`
- `app/Services/Analytics/DashboardAnalyticsService.php` — alinear idle con N
- `config/rbac.php` + SPA Header campana
- `frontend/src/pages/quotes/`, `frontend/src/components/layout/Header.tsx`

## E. Historial y notificación cruzada

- Cada cambio de seguimiento guarda evento: usuario, de→a, fecha, factura/comentarios.
- Visible en detalle para **Ventas y Compras**.
- Al guardar seguimiento, se notifica a **Compras** (campana “Seguimiento de ventas”).
- Destinatario de avisos Compras→Ventas sigue siendo ventas; el historial es bilateral.

- [ ] Capas separadas (workflow intacto)
- [ ] Elegibilidad Lista/Terminada + elaboracion idle ≥ N
- [ ] Dedupe unread por quote + reasonCode
- [ ] Negociación fecha ≥ hoy
- [ ] Ganada invoice 2–60; Perdida comments 3–500
- [ ] Destinatario = creador ventas
- [ ] Campana no fuerza Negociación en cerradas
- [ ] Tests Feature mínimos + sync VPS
