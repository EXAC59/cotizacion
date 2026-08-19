# Cotización

Sistema de cotizaciones con stack moderno:

| Capa | Tecnología |
|------|------------|
| Backend | **Laravel 13** (API REST) |
| Frontend | **React** + **TypeScript** (Vite) |
| Base de datos | **PostgreSQL** |
| Automatización / lectura | **n8n** |

## Estructura

```
cotizacion/
├── app/                 # Laravel (API, servicios n8n)
├── frontend/            # React + TypeScript
├── routes/api.php       # Endpoints REST
├── docker-compose.yml   # PostgreSQL + n8n
└── config/n8n.php       # Integración n8n
```

## Requisitos

- **Docker Desktop** (recomendado — levanta todo el stack), o
- PHP 8.3+, Composer, Node.js 20+, PostgreSQL (Laragon)

## Inicio rápido con Docker

```bash
cp .env.docker.example .env
docker compose up -d --build
```

| Servicio   | URL |
|------------|-----|
| API Laravel | http://localhost:8080/api/health |
| Frontend React | http://localhost:5173 |
| n8n | http://localhost:5678 |
| PostgreSQL | `localhost:5432` |

Comandos útiles (`Makefile`):

```bash
make up      # Levantar stack
make down    # Detener
make logs    # Ver logs
make shell   # Entrar al contenedor API
make migrate # Ejecutar migraciones
make prod    # Modo producción (build estático del frontend)
make staging # Staging VPS: subdominio único, colas, ver docs/deploy/staging-vps-docker.md
```

Estructura Docker:

```
docker/
├── nginx/default.conf    # Proxy PHP (Laravel)
├── php/Dockerfile        # PHP 8.3 + pdo_pgsql
├── postgres/init.sql     # Crea BD n8n
└── entrypoint.sh         # Composer + migraciones
frontend/Dockerfile         # Dev (Vite) / Prod (nginx)
docker-compose.yml
docker-compose.prod.yml
docker-compose.staging.yml   # Pruebas en dominio (VPS)
```

## Staging / pruebas en dominio (VPS)

Plantilla de entorno: [`.env.staging.example`](.env.staging.example)

Documentación:

- [Despliegue Docker en VPS](docs/deploy/staging-vps-docker.md)
- [Integración CT Internacional](docs/deploy/ct-internacional.md)

```bash
cp .env.staging.example .env   # editar STAGING_DOMAIN y secretos
make staging
./scripts/deploy-staging.sh    # Linux: migrate + seed + sync mayoristas
```

## Inicio rápido sin Docker (Laragon)

### 1. Backend (Laravel)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### 2. PostgreSQL en Laragon

Crea la base `cotizacion` y ajusta `DB_*` en `.env`.

### 3. Frontend (React + TypeScript)

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

### 4. n8n

Solo servicios de datos: `docker compose -f docker-compose.lectura.yml up -d`

Guía completa Docling + n8n: [docs/docling-lectura-local.md](docs/docling-lectura-local.md)

Ver [docs/n8n-flujo-lectura.md](docs/n8n-flujo-lectura.md).

## Frontend (React)

Aplicación SPA completa con todas las pantallas del sistema B2B:

| Ruta | Módulo |
|------|--------|
| `/login` | Autenticación |
| `/` | Dashboard |
| `/solicitudes` | Recepción y OCR |
| `/cotizaciones` | Constructor y profit |
| `/clientes` | CRM clientes |
| `/mayoristas` | Integraciones |
| `/reportes` | Analítica |
| `/configuracion` | Roles y parámetros |

```bash
cd frontend && npm install && npm run dev
```

**Solicitudes** y **clientes** usan la API Laravel y PostgreSQL. Cotizaciones y mayoristas se conectarán en fases posteriores.

### Objetivo 1 (§2.2) — lectura de solicitudes

- Docling OCR + `LecturaLineParser` (sin LLM)
- API: `/api/solicitudes`, `/api/clientes`, edición de líneas
- UI: nueva solicitud (Docling o n8n async), detalle con edición de líneas
- Verificación: `php artisan n8n:verify-lectura` y `php artisan test`
- Documentación: [docs/docling-lectura-local.md](docs/docling-lectura-local.md)

### Laragon (una sola URL)

1. Virtual host apuntando a `public/` (ej. **http://cotizacion.test**).
2. Compilar el frontend (solo cuando cambies React):

```bash
cd frontend && npm run build
```

3. Abrir la app:

| URL | Descripción |
|-----|-------------|
| **http://cotizacion.test/spa/** | Aplicación B2B (login, dashboard, etc.) |
| **http://cotizacion.test/** | Redirige a `/spa/` o muestra instrucciones si falta el build |
| **http://cotizacion.test/api/health** | API |

## API

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/health` | Estado del stack y conexiones |
| POST | `/api/n8n/lectura` | Recibe datos procesados por n8n |

## Variables de entorno

| Variable | Descripción |
|----------|-------------|
| `DB_*` | PostgreSQL |
| `N8N_BASE_URL` | URL de n8n |
| `N8N_WEBHOOK_URL` | Webhook para iniciar lectura |
| `DOCLING_BASE_URL` | Docling Serve (lectura PDF/Excel/Word + OCR) |
| `FRONTEND_URL` | Origen CORS del frontend |
| `VITE_API_URL` | URL de la API para React |

## Laragon

Añade el virtual host `cotizacion.test` apuntando a `public/`.

URLs típicas:

- API: http://cotizacion.test/api/health
- Frontend: http://localhost:5173
# cotizacion
