# Runbook VPS — exacto.mx (Cotización)

Operaciones diarias en `root@2.25.78.222`, ruta `/var/www/cotizacion`.

## Acceso rápido

| Recurso | URL / comando |
|---------|----------------|
| SPA | https://exacto.mx/spa/ |
| API health | https://exacto.mx/api/health |
| n8n UI | http://2.25.78.222:5678 |
| SSH | `ssh root@2.25.78.222` |
| Sync desde Windows | `.\scripts\sync-to-vps.ps1` |

Compose en servidor:

```bash
cd /var/www/cotizacion
DC="docker compose -f docker-compose.yml -f docker-compose.staging.yml"
```

---

## Reinicios (sin pérdida de datos)

### Solo API / colas (tras cambio PHP, rutas, config)

```bash
$DC restart api queue scheduler
```

### Frontend (tras `npm run build` local o rebuild en VPS)

```bash
$DC build frontend
$DC up -d frontend
```

### n8n / Docling

```bash
$DC restart n8n docling-serve
```

### Todo el stack (mantenimiento)

```bash
$DC up -d
```

### Ver estado

```bash
$DC ps
$DC logs api --tail 50
$DC logs queue --tail 50
$DC logs n8n --tail 50
```

---

## Logs

| Qué | Dónde |
|-----|--------|
| Laravel (errores app) | `$DC exec -T api tail -100 storage/logs/laravel.log` |
| API nginx/php | `$DC logs api --tail 100` |
| Cola jobs | `$DC logs queue --tail 100` |
| Scheduler | `$DC logs scheduler --tail 50` |
| n8n ejecuciones | UI → Executions, o `$DC logs n8n --tail 100` |
| PostgreSQL | `$DC logs postgres --tail 50` |

Buscar solicitudes atascadas en BD:

```bash
$DC exec -T postgres psql -U cotizacion -d cotizacion -c \
  "SELECT id, status, file_name, updated_at FROM quote_requests WHERE status='procesando' ORDER BY updated_at;"
```

---

## Verificaciones n8n / lectura

```bash
$DC exec -T api php artisan n8n:verify-lectura
$DC exec -T api php artisan n8n:verify-comparador
$DC exec -T api php artisan docling:ping
```

Variables críticas en `.env`:

```bash
grep -E '^(N8N_|DOCLING_)' .env
```

- `N8N_WEBHOOK_URL=http://n8n:5678/webhook/lectura-docling`
- `N8N_WEBHOOK_SECRET=` (obligatorio en producción; header `X-N8N-Webhook-Secret`)
- Login SPA: `throttle:6,1` en `POST /api/login`
- n8n en staging: bind `127.0.0.1:5678` + UFW deny; ver `scripts/vps-security-harden.sh`
- `N8N_COMPARATOR_WEBHOOK_URL=http://n8n:5678/webhook/comparador-precios`

---

## Solicitud atascada en «Procesando»

1. Obtener UUID en la UI o en BD (consulta arriba).
2. Revisar n8n → workflow **Lectura cotizacion** → Executions (última ejecución).
3. Reenviar a n8n:

```bash
$DC exec -T api php artisan solicitud:reenviar-n8n {UUID}
```

4. Bypass sin n8n (solo emergencia):

```bash
$DC exec -T api php artisan solicitud:procesar-local {UUID}
```

5. Alertas automáticas: dashboard → **Solicitudes atascadas** (umbral `DASHBOARD_STUCK_REQUEST_MINUTES`, default 15).

```bash
$DC exec -T api php artisan solicitud:alertar-atascadas
```

---

## Backup

### Base de datos (recomendado diario)

```bash
mkdir -p /var/backups/cotizacion
$DC exec -T postgres pg_dump -U cotizacion -Fc cotizacion \
  > /var/backups/cotizacion/db-$(date +%Y%m%d-%H%M).dump
```

Restaurar (¡destructivo en BD destino!):

```bash
$DC exec -T postgres pg_restore -U cotizacion -d cotizacion --clean --if-exists < archivo.dump
```

### Archivos subidos (solicitudes)

```bash
tar -czf /var/backups/cotizacion/storage-$(date +%Y%m%d).tar.gz \
  -C /var/www/cotizacion/storage app solicititudes 2>/dev/null || \
tar -czf /var/backups/cotizacion/storage-$(date +%Y%m%d).tar.gz \
  /var/www/cotizacion/storage
```

### Configuración (sin secretos en git)

```bash
cp /var/www/cotizacion/.env /var/backups/cotizacion/env-$(date +%Y%m%d).bak
```

Retención sugerida: 7 días DB, 30 días `.env`.

### Automático (cron diario 03:00)

```bash
bash scripts/vps-backup-install-cron.sh   # una vez
bash scripts/vps-backup-daily.sh        # prueba manual
tail -20 /var/log/cotizacion-backup.log
```

Variables opcionales: `BACKUP_DB_KEEP_DAYS=7`, `BACKUP_ENV_KEEP_DAYS=30`, `BACKUP_STORAGE_KEEP_DAYS=7`.

---

## Sync código desde Windows

```powershell
cd c:\laragon\www\cotizacion
.\scripts\sync-to-vps.ps1
# Solo backend: .\scripts\sync-to-vps.ps1 -SkipFrontendBuild
```

Credenciales: `scripts/.vps-deploy.local` (no se sube a git).

Tras sync con cambios en `frontend/`, el script hace rebuild de `frontend` en VPS. Recargar SPA con **Ctrl+F5**.

---

## Certificado HTTPS

Renovación (Certbot en contenedor nginx según `vps-phase2-integrations-https.sh`):

```bash
$DC exec nginx certbot renew --dry-run
```

Si falla HTTPS, revisar DNS de `exacto.mx` → `2.25.78.222`.

### n8n con HTTPS (subdominio)

Requisito: DNS `n8n.exacto.mx` → IP del VPS.

**Nota:** Activar SSL en el panel de Hostinger solo crea el registro DNS; en el VPS hace falta nginx + certificado Let's Encrypt para el subdominio (certificado de `exacto.mx` no cubre `n8n.exacto.mx`).

```bash
N8N_DOMAIN=n8n.exacto.mx CERTBOT_EMAIL=tu@exacto.mx bash scripts/vps-enable-n8n-https.sh
```

UI: `https://n8n.exacto.mx/` — webhooks internos Laravel siguen en `http://n8n:5678`.

Estado actual (jul 2026): certificado `n8n.exacto.mx` activo, UI y webhooks verificados.

---

## Monitoreo externo

Guía UptimeRobot: [monitoreo-uptimerobot.md](./monitoreo-uptimerobot.md)

URL principal: `https://exacto.mx/api/health` (keyword `"status":"ok"`).

---

## Contactos / escalación

| Síntoma | Acción |
|---------|--------|
| 502 / API caída | `$DC ps`, `$DC restart api nginx` |
| n8n no responde | `$DC restart n8n`, puerto 5678 firewall Hostinger |
| Docling timeout | `$DC restart docling-serve`, revisar RAM VPS |
| Comparador sin ofertas | Credenciales mayoristas en `.env`, `n8n:verify-comparador` |
| CT API timeout | Puertos **4000/3001** en UFW + Hostinger firewall; IP `2.25.78.222` en CT Connect |

---

## Checklist post-deploy

- [ ] https://exacto.mx/api/health → OK
- [ ] Login SPA demo/admin
- [ ] `n8n:verify-lectura` y `n8n:verify-comparador` OK
- [ ] Workflow n8n **Lectura cotizacion** activo (verde)
- [ ] Prueba texto libre + un PDF/Excel
