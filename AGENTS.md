# Cotización — guía para agentes

Sistema de solicitudes, comparador de precios y cotizaciones (Laravel + React SPA).
Producción: `https://cotizaciones.exacto.mx/spa/` (`exacto.mx` redirige a `exacto.com.mx`).

## Stack

| Capa | Tecnología |
|------|------------|
| Backend | Laravel, Sanctum, PostgreSQL |
| Frontend | React 19, TypeScript, Vite, Tailwind 4 |
| Integraciones | n8n (lectura/comparador), Docling (Docker opcional) |
| Deploy SPA | `frontend/` → build → `public/spa/` |

## Estructura del repo

```
app/Http/Controllers/Api/   # REST API
app/Services/               # Lógica de negocio
frontend/src/               # SPA React
routes/api.php              # Rutas API
routes/web.php              # Fallback SPA + caché
database/migrations/        # Esquema PostgreSQL
tests/                      # PHPUnit Feature/Unit
.cursor/rules/              # Reglas Cursor del proyecto
```

## Módulos del menú (SPA)

- Dashboard, Solicitudes, Cotizaciones, Clientes, Mayoristas, Reportes, Configuración, Administración
- RBAC por módulo/acción (`config/rbac.php`)

## Cuentas demo (desarrollo)

- Ventas: `maria@empresa.com` / `demo`
- Admin: `admin@cotizacion.test` / `admin123`

## Comandos habituales

```powershell
# Tests backend
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe vendor/bin/phpunit

# Migración puntual (ejemplo)
php artisan migrate --path=database/migrations/NOMBRE.php --force

# Frontend
cd frontend
npm run build
npm run lint
```

Tras `npm run build`, el usuario debe recargar con **Ctrl+F5** (caché del SPA).

> **SIEMPRE subir los cambios al VPS** (`root@2.25.78.222`, llave `~/.ssh/id_ed25519_exacto_vps`, ruta `/var/www/cotizacion`) al terminar una tarea. Frontend SPA: `rsync -az frontend/src → VPS:frontend/src` y `docker compose -f docker-compose.yml -f docker-compose.staging.yml build --no-cache frontend && ... up -d --force-recreate frontend` (el contenedor `frontend` sirve el build desde su imagen, no del bind mount).

## API relevante

- `GET/POST /api/cotizaciones` — listado y guardado
- `GET /api/cotizaciones/proximo-folio` — folio autogenerado
- `POST /api/solicitudes/lectura` — archivos (n8n/docling)
- `POST /api/solicitudes/lectura-texto` — texto libre
- `POST /api/comparador/disparar` — comparador de precios
- `GET /api/mayoristas/ct/autocomplete?sku=&descripcion=` — catálogo CT (AND si ambos)
- `GET /api/dashboard` — KPIs y alertas

## CT / Docker

- Catálogo FTP + reglas de matching: `docs/deploy/ct-internacional.md`
- Contenedor `api` recibe `WHOLESALER_CT_*` desde `docker-compose.yml` (+ staging)
- SKU + descripción juntos → coincidencia en ambos; ≥3 matches → multi-lookup de precios

## Reglas Cursor (`.cursor/rules/`)

| Regla | Alcance |
|-------|---------|
| `gestion-tiempo-bloques.mdc` | Siempre — bloques optimización / errores / cambios |
| `no-modificar-existente.mdc` | Siempre — no alterar lo ya funcionando |
| `sync-vps-auto.mdc` | Siempre — subir cambios al VPS al cerrar cada tarea de código |
| `2fa-futuro.mdc` | Siempre — 2FA pendiente, no implementar hasta aviso |
| `wholesaler-sales-aliases.mdc` | Siempre — alias BODEGAxx para rol ventas |
| `cotizacion-laravel.mdc` | PHP backend |
| `cotizacion-frontend.mdc` | Carpeta `frontend/` |
| `ct-catalog-docker.mdc` | CT catálogo / autocomplete / compose |

## Principios de trabajo

1. Cambios mínimos y enfocados en lo pedido.
2. No modificar flujos existentes sin solicitud explícita.
3. Reutilizar servicios y componentes del proyecto.
4. Verificar tests y build antes de cerrar tareas de código.
