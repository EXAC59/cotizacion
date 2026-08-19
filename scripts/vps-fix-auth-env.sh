#!/usr/bin/env bash
# Corrige Sanctum/sesión para SPA en exacto.mx (login que vuelve al inicio).
# Uso en VPS: cd /var/www/cotizacion && bash scripts/vps-fix-auth-env.sh
set -eu

cd "$(dirname "$0")/.."
DOMAIN="${STAGING_DOMAIN:-exacto.mx}"

python3 <<PY
from pathlib import Path
p = Path(".env")
lines = p.read_text().splitlines()
updates = {
  "STAGING_DOMAIN": "${DOMAIN}",
  "APP_URL": f"https://${DOMAIN}",
  "FRONTEND_URL": f"https://${DOMAIN}",
  "APP_PORT": "8080",
  "SANCTUM_STATEFUL_DOMAINS": f"${DOMAIN},www.${DOMAIN}",
  "SESSION_DOMAIN": "",
  "SESSION_SECURE_COOKIE": "true",
  "SESSION_ENCRYPT": "true",
  "SESSION_DRIVER": "database",
  "DB_HOST": "postgres",
}
out, seen = [], set()
db_user = None
db_password = None
for line in lines:
    if "=" in line and not line.strip().startswith("#"):
        k, _, v = line.partition("=")
        if k == "DB_USERNAME":
            db_user = v
        if k == "DB_PASSWORD":
            db_password = v
        if k in updates:
            out.append(f"{k}={updates[k]}")
            seen.add(k)
            continue
    out.append(line)
if db_user == "postgres":
    out = [f"DB_USERNAME=cotizacion" if l.startswith("DB_USERNAME=") else l for l in out]
if db_password in ("dulce123", "secret", "change_me_staging_db"):
    out = [f"DB_PASSWORD=TuPasswordSegura123" if l.startswith("DB_PASSWORD=") else l for l in out]
for k, v in updates.items():
    if k not in seen:
        out.append(f"{k}={v}")
p.write_text("\n".join(out) + "\n")
print("OK .env auth")
PY

DC=(docker compose -f docker-compose.yml -f docker-compose.staging.yml)
"${DC[@]}" exec -T api php artisan config:clear
"${DC[@]}" restart api queue frontend
echo "Listo. Recarga https://${DOMAIN}/spa/ con Ctrl+F5 e inicia sesión."
