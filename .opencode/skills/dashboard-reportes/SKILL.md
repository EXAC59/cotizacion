---
name: dashboard-reportes
description: >-
  Dashboard (KPIs, alertas, productos top) y reportes (ganancia por vendedor,
  tendencia, top mayoristas). Usar cuando la tarea involucre el módulo
  Dashboard o Reportes, DashboardAnalyticsService, DashboardController,
  ReportesController, config/dashboard.php o los endpoints /api/dashboard y
  /api/reportes.
---

# Dashboard y Reportes — analítica de negocio

## API (Sanctum)

- `GET /api/dashboard?from=&to=` — KPIs + alertas + recientes + pendientes
- `GET /api/reportes?from=&to=` — KPIs + ganancia por vendedor + tendencia + top
- Permisos: `permission:dashboard,view` y `permission:reportes,view`

## Lógica (DashboardAnalyticsService)

- **Período**: `resolvePeriod(from, to)`; si no vienen fechas, mes en curso. Si `from > to`, los intercambia.
- **KPIs** (`kpiBlock`): `monthlyRealizedProfit` (estados ganadores), `monthlyPotentialProfit` (todos), `averageTicket`, `wonQuotes`, `lostQuotes`, `winRate`.
  - Ganancia por línea = `quantity * (sale_price - cost)`.
  - Estados ganadores: `config('quotes.won_statuses')` → `aceptada`, `facturada`.
- **Alertas** (`alertsBlock`): lowStock, unansweredQuotes, readyForSalesQuotes (`pendiente_envio`), integrationIssues (mayoristas sin credenciales + jobs con error), expiringQuotes, stuckProcessingRequests (`status=procesando` atascadas), pendingReviewRequests.
- **Reportes** (`reportesPayload`): añade `profitBySalesperson` (join users), `profitTrend` (6 meses), `topWholesalers` (% de uso en `quote_line_offers`).
- **Top productos**: `topRequestedProducts` (quote_request_lines) y `topQuotedProducts` (quote_lines), agrupados por `part_number` si existe, sino `product`, sumando cantidad.

## Configuración

- `config/dashboard.php`: `stuck_request_minutes`, `low_stock_offer_lookback_days`, `expiring_quote_days`, `integration_error_lookback_days`.
- Umbral de stock bajo: `AppSetting::current()->min_stock_alert` (si ≤0 no hay alertas de stock).

## Frontend

- Páginas: `frontend/src/pages/dashboard/DashboardPage.tsx`, `frontend/src/pages/reports/ReportsPage.tsx`
- Lib: `frontend/src/lib/dashboard-api.ts`, `frontend/src/lib/inventory-alerts.ts`
- UI: `StatCard`, `Badge`, `LoadingState`

## Verificación

- Tests: `DashboardAnalyticsTest`, `LowStockPollTest`; endpoint `/health` en `HealthEndpointTest`.