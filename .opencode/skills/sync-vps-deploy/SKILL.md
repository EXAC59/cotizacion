---
name: sync-vps-deploy
description: >-
  Subir cambios al VPS (exacto.mx / exacto.com.mx) y desplegar el SPA. Usar al
  cerrar cualquier tarea de código en este repositorio (PHP, React, Docker,
  n8n, scripts). Credenciales en scripts/.vps-deploy.local (gitignored).
---

# Deploy / Sync al VPS

## Regla

Tras **cualquier cambio local** que afecte el deploy (PHP, React, Docker, n8n, scripts), subir al VPS sin que el usuario lo pida. Tareas de solo lectura no se sincronizan.

## Destino

- Host: `root@2.25.78.222`, ruta `/var/www/cotizacion`
- URL prod: `https://cotizaciones.exacto.mx/spa/` → `127.0.0.1:8080` → contenedor `cotizacion_frontend`
- `exacto.mx` redirige a `https://exacto.com.mx/`
- Auth: `scripts/.vps-deploy.local` (`sshpass` + password o llave; gitignored)

## Pasos (Linux/Debian)

1. Revisar cambios: `git status` / `git diff` (o listar archivos tocados).
2. Empaquetar los archivos tocados con tar; si cambió `frontend/`, incluir `public/spa/` (build previo).
3. `scp` + `tar -xzf` en `/var/www/cotizacion`.
4. Si cambió `app/`, `routes/`, `config/`, `database/`:
   `docker compose exec -T api php artisan optimize:clear` (y restart api/queue si aplica).
5. Si cambió `frontend/`:
   ```bash
   cd frontend && npm run build        # local primero
   # sync fuentes + public/spa
   docker compose -f docker-compose.yml -f docker-compose.staging.yml build --no-cache frontend
   docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --force-recreate frontend
   ```
6. Verificar: `curl -sS http://127.0.0.1:8080/spa/ | head` → el hash JS nuevo.

## Windows (PowerShell)

`.\scripts\sync-to-vps.ps1` (reinicia API si cambió app/routes/config; rebuild frontend si cambió frontend).

## Excepciones

- **No** subir `.env` local (solo `.env.staging` si se cambia a propósito).
- **No** ejecutar migraciones destructivas en VPS sin pedido explícito.
- Cambios solo en tests/docs internos: sync opcional.

## Si falla

1. Verificar `scripts/.vps-deploy.local` (host, hostkey, password/llave).
2. Prueba de conexión `ssh root@2.25.78.222`.
3. Reportar el error al usuario; **no** cerrar la tarea de código sin sync exitosa o motivo claro.