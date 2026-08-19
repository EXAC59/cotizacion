# Monitoreo externo — UptimeRobot

Alertas si `exacto.mx` o la API dejan de responder.

## URL a vigilar

| Monitor | URL | Tipo |
|---------|-----|------|
| API health (principal) | `https://exacto.mx/api/health` | HTTP(s) |
| SPA (opcional) | `https://exacto.mx/spa/` | HTTP(s) keyword `Cotización` o status 200 |

## Respuesta esperada de `/api/health`

```json
{
  "status": "ok",
  "services": {
    "database": { "ok": true }
  }
}
```

UptimeRobot: **Keyword monitor** con keyword `"status":"ok"` o validar HTTP 200 + JSON.

## Pasos en UptimeRobot (gratis)

1. Crear cuenta en [https://uptimerobot.com](https://uptimerobot.com)
2. **Add New Monitor**
   - Monitor Type: **HTTP(s)**
   - Friendly Name: `Cotización API`
   - URL: `https://exacto.mx/api/health`
   - Monitoring Interval: 5 minutes
3. **Alert Contacts** → email o Telegram
4. Guardar

## Monitor opcional: n8n

Solo si expusiste n8n con HTTPS (`n8n.exacto.mx`):

- URL: `https://n8n.exacto.mx/healthz` o raíz (200)

Sin HTTPS, no monitorear puerto 5678 desde fuera (firewall).

## Qué hacer si cae

Ver [vps-runbook.md](./vps-runbook.md):

```bash
cd /var/www/cotizacion
docker compose -f docker-compose.yml -f docker-compose.staging.yml ps
docker compose ... restart api nginx
curl -s https://exacto.mx/api/health
```

## Alternativas

- **Hostinger** — alertas de CPU/RAM en hPanel
- **Cron + curl** en VPS (sin servicio externo):

```bash
# /etc/cron.hourly/cotizacion-health (ejemplo)
curl -fsS https://exacto.mx/api/health || logger -t cotizacion-health "API DOWN"
```
