# Despliegue staging en VPS (Docker)

Guía para montar el proyecto en un **subdominio único** (SPA + API mismo origen) con Docker Compose.

## Requisitos

- VPS con Docker y Docker Compose v2
- DNS: `cotizacion.tudominio.com` → IP de la VPS
- Puertos 80 (y 443 con Certbot) abiertos

## Despliegue en exacto.mx (VPS Hostinger)

| Dato | Valor |
|------|--------|
| VPS | `root@2.25.78.222` (`srv1809665.hstgr.cloud`) |
| Ruta | `/var/www/cotizacion` |
| DNS | `exacto.mx` y `www.exacto.mx` → `2.25.78.222` |
| Estado previo | HTTP en puerto `8080` (`vps-fix-deploy.sh`) |

### Desde Windows

```powershell
cd c:\laragon\www\cotizacion
.\scripts\setup-staging-env.ps1 -Domain exacto.mx
.\scripts\sync-docker-to-vps.ps1
# (pedirá contraseña root de Hostinger)
```

O doble clic: `scripts\vps-deploy-exacto.cmd`

### En el VPS — HTTPS + integraciones

```bash
cd /var/www/cotizacion
cp .env.staging .env
nano .env   # DB_PASSWORD, revisar APP_KEY
DOMAIN=exacto.mx CERTBOT_EMAIL=tu@exacto.mx bash scripts/vps-phase2-integrations-https.sh
```

Tras Certbot:

- App: **https://exacto.mx/spa/**
- API: **https://exacto.mx/api/health**
- n8n: `http://2.25.78.222:5678` (abrir puerto 5678 en firewall Hostinger)

Importar en n8n (con Host ya ajustado a `exacto.mx`):

- `docs/n8n/lectura-cotizacion.workflow.json`
- `docs/n8n/comparador-precios.workflow.json`

## 1. Preparar entorno

En el servidor, dentro del repositorio:

```bash
cp .env.staging.example .env
nano .env   # editar STAGING_DOMAIN, DB_PASSWORD, APP_KEY, CT, etc.
```

Variables críticas:

| Variable | Ejemplo |
|----------|---------|
| `STAGING_DOMAIN` | `cotizacion.tudominio.com` |
| `APP_URL` / `FRONTEND_URL` | `https://cotizacion.tudominio.com` |
| `SANCTUM_STATEFUL_DOMAINS` | mismo dominio sin `https://` |
| `SESSION_DOMAIN` | mismo dominio |
| `VITE_API_URL` | `/api` |

Generar `APP_KEY` si está vacío:

```bash
docker compose -f docker-compose.yml -f docker-compose.staging.yml run --rm api php artisan key:generate
```

En Windows (local) puedes generar `.env.staging`:

```powershell
.\scripts\setup-staging-env.ps1 -Domain cotizacion.tudominio.com
# HTTP local: .\scripts\setup-staging-env.ps1 -Domain localhost -UseHttp
```

## 2. Levantar stack

```bash
chmod +x scripts/deploy-staging.sh
./scripts/deploy-staging.sh
```

O manualmente:

```bash
make staging
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec api php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec api php artisan db:seed --class=DashboardUsersSeeder
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec api php artisan wholesalers:sync-catalog
```

## 3. HTTPS (cuando la VPS esté lista)

1. Instalar Certbot en el host
2. Usar plantilla [`docker/nginx/staging-host.conf.example`](../../docker/nginx/staging-host.conf.example)
3. Actualizar `.env` con `https://` en `APP_URL` y `FRONTEND_URL`
4. Reiniciar: `docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d`

## 4. Verificación

```bash
curl -s https://cotizacion.tudominio.com/api/health
curl -I https://cotizacion.tudominio.com/spa/
```

Login demo (tras seed): `admin@cotizacion.test` / `admin123`

## 5. Servicios

| Servicio | Acceso |
|----------|--------|
| Frontend (nginx) | Puerto `FRONTEND_PORT` (80) — única cara pública |
| API Laravel | Interno vía `/api` y `/sanctum` |
| PostgreSQL | Solo red Docker |
| Cola correos | Contenedor `queue` (`queue:work`) |
| Scheduler | Contenedor `scheduler` |
| n8n + Docling | Perfil `integrations` (opcional): `docker compose --profile integrations ...` |

## 7. Fase 2 — n8n, Docling, HTTPS, sincronizar Docker

### Desde tu PC (Windows)

```powershell
cd c:\laragon\www\cotizacion
.\scripts\sync-docker-to-vps.ps1
```

Sube `docker-compose*.yml`, Dockerfiles, nginx y scripts al VPS.

### En el VPS — integraciones (IP actual, sin HTTPS)

```bash
cd /var/www/cotizacion
dos2unix scripts/*.sh docker/entrypoint.sh 2>/dev/null || true
bash scripts/vps-phase2-integrations-https.sh
```

Abrir **puerto 5678** en firewall Hostinger para la UI de n8n.

### HTTPS (requiere dominio DNS → VPS)

```bash
DOMAIN=cotizacion.tudominio.com CERTBOT_EMAIL=admin@tudominio.com \
  bash scripts/vps-phase2-integrations-https.sh
```

Let's Encrypt **no funciona solo con IP**; hace falta un registro A del dominio.

### Tras levantar n8n

1. UI: `http://TU_IP:5678`
2. Importar `docs/n8n/lectura-cotizacion.workflow.json` y `docs/n8n/comparador-precios.workflow.json`
3. Verificar: `php artisan n8n:verify-lectura` y `n8n:verify-comparador`

## 6. CT Internacional

Ver [ct-internacional.md](ct-internacional.md).

## Prueba local sin dominio

```bash
cp .env.staging.example .env
# Editar: STAGING_DOMAIN=localhost, APP_URL=http://localhost, SESSION_SECURE_COOKIE=false
make staging
```
