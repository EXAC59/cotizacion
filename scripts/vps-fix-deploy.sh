#!/usr/bin/env bash
# Reparar despliegue staging en VPS (puertos fantasma + frontend nginx).
# Uso en el servidor: bash scripts/vps-fix-deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo ">> Detener stack y limpiar contenedores fantasma..."
docker compose -f docker-compose.yml -f docker-compose.staging.yml down --remove-orphans 2>/dev/null || true
docker rm -f cotizacion_frontend 2>/dev/null || true
docker container prune -f

echo ">> Reiniciar Docker (libera puertos reservados)..."
systemctl restart docker
sleep 5

echo ">> Configurar .env para puerto 8080..."
sed -i 's|^APP_PORT=.*|APP_PORT=8080|' .env
sed -i 's|^FRONTEND_PORT=.*|FRONTEND_PORT=8080|' .env
sed -i 's|^APP_URL=.*|APP_URL=http://2.25.78.222:8080|' .env
sed -i 's|^FRONTEND_URL=.*|FRONTEND_URL=http://2.25.78.222:8080|' .env
sed -i 's|^STAGING_DOMAIN=.*|STAGING_DOMAIN=2.25.78.222:8080|' .env
sed -i 's|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=2.25.78.222:8080|' .env
sed -i 's|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|' .env
grep -E '^(APP_PORT|APP_URL|APP_KEY)=' .env || true

echo ">> frontend.conf con resolver Docker..."
cat > frontend/docker/nginx/frontend.conf << 'NGINXEOF'
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    charset utf-8;
    client_max_body_size 64M;

    resolver 127.0.0.11 valid=30s ipv6=off;

    location = / {
        return 302 /spa/;
    }

    location /spa/ {
        try_files $uri $uri/ /spa/index.html;
    }

    location /api {
        set $api_upstream cotizacion_nginx:80;
        proxy_pass http://$api_upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
    }

    location /sanctum {
        set $api_upstream cotizacion_nginx:80;
        proxy_pass http://$api_upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINXEOF

dos2unix docker/entrypoint.sh frontend/docker/nginx/frontend.conf 2>/dev/null || true

echo ">> Dockerfile: dist debe ir en html/spa/ (Vite base=/spa/)..."
sed -i 's|/usr/share/nginx/html$|/usr/share/nginx/html/spa|' frontend/Dockerfile
grep 'html/spa' frontend/Dockerfile

echo ">> postgres en red cotizacion (staging override)..."
if ! grep -A5 '^  postgres:' docker-compose.staging.yml 2>/dev/null | grep -q cotizacion; then
  sed -i '/^services:$/a\  postgres:\n    networks:\n      - cotizacion\n' docker-compose.staging.yml
fi

echo ">> Build frontend + levantar stack..."
docker compose -f docker-compose.yml -f docker-compose.staging.yml build --no-cache frontend
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d

echo ">> Esperando servicios..."
sleep 20

echo ">> Estado:"
docker compose -f docker-compose.yml -f docker-compose.staging.yml ps

echo ">> Logs frontend (últimas líneas):"
docker logs cotizacion_frontend 2>&1 | tail -8 || true

echo ">> Prueba local:"
curl -fsS -I http://127.0.0.1:8080/spa/ | head -5 || echo "curl /spa/ falló"
curl -fsS http://127.0.0.1:8080/api/health || echo "curl /api/health falló"

echo ">> Migraciones..."
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan db:seed --class=DashboardUsersSeeder --force

echo ""
echo "LISTO. Abrí: http://2.25.78.222:8080/spa/"
echo "Login demo: admin@cotizacion.test / admin123"
