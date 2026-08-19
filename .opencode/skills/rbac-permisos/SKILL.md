---
name: rbac-permisos
description: >-
  RBAC del proyecto: módulos, acciones, roles, middleware permission, ModuleRoute
  y usePermission en la SPA. Usar al añadir una ruta API, un botón, una página o
  al revisar permisos/roles en Laravel+React (config: config/rbac.php).
---

# RBAC — módulos, acciones y roles

## Config central: `config/rbac.php`

- **Módulos**: `dashboard, solicitudes, cotizaciones, clientes, mayoristas, reportes, configuracion, admin`
- **Acciones por módulo** (lista exacta):
  - `dashboard` → `view`
  - `solicitudes` → `view, create, edit, delete`
  - `cotizaciones` → `view, create, edit, delete, send, approve, edit_margin`
  - `clientes` → `view, create, edit, delete, import`
  - `mayoristas` → `view, edit`
  - `reportes` → `view`
  - `configuracion` → `view, edit`
  - `admin` → `view, manage`
- **Roles**: `administrador` (todo), `gerente_compras`, `ventas`
- `default_role_permissions` marca qué rol puede qué. El admin no tiene subido a rol.

## Backend

- Middleware: `permission:{modulo},{accion}` en `routes/api.php`.
  - `permission:mayoristas,view` → consultar/comparar
  - `permission:cotizaciones,create|edit` → autocomplete CT/CVA
  - `permission:admin,manage` → usuarios/RBAC
  - `permission:configuracion,edit` → actualizar config comparador
  - `permission:clientes,import` → importar clientes
- También `permission:admin,manage` para RBAC controller.
- Servicio: `App\Services\Rbac\RbacService`; middleware `CheckPermission`.

## Frontend

- `usePermission('modulo', 'accion')` — hook (opencode remarca: revisar `useRbac.ts`/`usePermission.ts`).
- `ModuleRoute` — wrapper de ruta por módulo del menú (`frontend/src/components/ModuleRoute.tsx`).
- `AdminRoute` — de rutas admin.
- Tipos: `frontend/src/types/rbac.ts`, `data/rbac-defaults.ts`.
- Lib: `frontend/src/lib/permissions.ts`, `rbac-api.ts`.

## Reglas

- Al añadir un endpoint, declarar el middleware de permiso; nunca dejar una ruta autenticada sin permiso.
- Agregar siempre la/s acción/es al `module_actions` y al `default_role_permissions` del/los rol(es) correcto(s).
- El front debe usar `usePermission`/`ModuleRoute` para ocultar botones según rol (ventas no ve mayoristas ni reportes).

## Verificación

- Tests: `RbacPermissionsTest`, `ApiAuthorizationTest`, `AdminUsersApiTest`; migraciones `extend_rbac_business_actions`, `sync_missing_rbac_defaults`, `remove_gerencia_and_solo_lectura_roles`.