---
name: configuracion-ajustes
description: >-
  Configuración comercial (márgenes, impuestos, empresa, logo), configuración del
  comparador (pesos, prioridad de almacenes, penalizaciones) y preferencias por
  usuario y branding público. Usar cuando la tarea involucre el módulo
  Configuración, ConfiguracionController, AppSetting, ComparatorSetting,
  UserComparatorPreferenceService o los endpoints /api/configuracion.
---

# Configuración — comercial, comparador y preferencias

## API

- Público: `GET /api/configuracion/branding` (throttle 60/min) — nombre, subtítulo, logo para login/landing.
- Comercial (Sanctum): `GET /api/configuracion/comercial`, `PATCH /api/configuracion/comercial`, `POST /api/configuracion/comercial/logo`.
- Comparador: `GET/PATCH /api/configuracion/comparador`, `GET/PATCH /api/configuracion/comparador/preferencias`.
- Permisos: `permission:configuracion,view|edit`; comercial usa middleware `commercial.settings`; preferencias usan `permission:mayoristas,view`.

## Modelos y campos

- **`AppSetting::current()`** (singleton en `app_settings`):
  - Comercial: `default_margin_percent`, `tax_percent`, `quote_validity_days`, `min_stock_alert`, `currency_code`, `company_*`, `quote_terms`, `quote_signature_*`, `quote_footer_address`, `bank_accounts`, `logo_path`, `app_branding` (app_name, app_tagline).
  - Métodos: `toCommercialApiArray()`, `toBrandingApiArray()`, `resolvedUnansweredQuoteDays()`.
  - `unansweredQuoteDays` solo lo actualiza el admin (`role_slug === 'administrador'`).
- **`ComparatorSetting::current()`**: `weights` (suman 1.0), `warehouse_priority`, `preferred_wholesaler_ids`, `import_penalty`, `lead_day_penalty`, `min_stock_threshold`. `normalizedWeights()` para merge.
- **Preferencias por usuario** (`UserComparatorPreferenceService`): `preferredWarehouse(s)`, `autoApplyBest`, `preferredWhitelisterIds`; clave por email.

## Reglas

- Emails vacíos en updateComercial → tratarlos como null antes de validar `email`.
- Al actualizar pesos del comparador, hacer merge con `normalizedWeights()` (no sobrescribir campos faltantes).
- Logo: `mimes:png,jpg,jpeg,webp`, máx 2048 KB; se sube a `Storage::disk('public')` en `company/logo.{ext}`; borra el anterior.
- Preferencias de usuario: max 500 almacenes, cada uno max 40 chars.

## Frontend

- Página: `frontend/src/pages/settings/SettingsPage.tsx`
- Componentes: `PreferredWarehousesByWholesaler`
- Lib: `frontend/src/lib/branding-api.ts`, `comparator-settings-api.ts`, `preferred-warehouses.ts`

## Verificación

- Tests: `AppBrandingApiTest`, `ComparatorSettingsTest`, `CompanyBranchesParserTest` (unit).