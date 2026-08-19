---
description: Sincroniza los cambios locales al VPS (exacto.mx / exacto.com.mx) siguiendo la skill sync-vps-deploy.
agent: build
---

Sube los cambios locales al VPS siguiendo la skill `sync-vps-deploy`:

1. Carga y aplica la skill `sync-vps-deploy`.
2. Revisa qué cambió (`git status`/`git diff`, o lista de archivos tocados).
3. Empaqueta y sube a `root@2.25.78.222` en `/var/www/cotizacion`.
4. Si cambió `app/`, `routes/`, `config/`: ejecuta `docker compose exec -T api php artisan optimize:clear`.
5. Si cambió `frontend/`: construye primero (`npm run build`) y luego rebuild + recreate del contenedor `frontend`.
6. Verifica con `curl -sS http://127.0.0.1:8080/spa/ | head` que el hash JS es el nuevo.
7. Reporta el resultado; **no** dar por cerrada la tarea si la sync falló sin razón clara.

Argumentos: `$ARGUMENTS` opcional para notas (p. ej. `solo API`, `solo frontend`).