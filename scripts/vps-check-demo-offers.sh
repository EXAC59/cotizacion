#!/usr/bin/env bash
set -euo pipefail
cd /var/www/cotizacion
echo '=== ENV ==='
grep -E 'WHOLESALER_DEMO|WHOLESALER_COMPARE' .env || true
echo '=== SPA strings ==='
grep -R --include='*.js' -l 'Exel del Norte\|demo-ingram\|Vista previa demo\|buildFallback' public/spa 2>/dev/null | head -10 || echo 'none in public/spa'
ls -lt public/spa/assets/*.js 2>/dev/null | head -5
echo '=== frontend container assets ==='
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T frontend sh -c "grep -R --include='*.js' -l 'Exel del Norte\|Vista previa demo' /usr/share/nginx/html 2>/dev/null | head -5 || ls -la /usr/share/nginx/html/assets 2>/dev/null | head -10" || true
echo '=== active mayoristas ==='
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan tinker --execute="echo App\Models\Wholesaler::query()->where('active',true)->pluck('code')->implode(',');"
echo '=== config demo ==='
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan tinker --execute="echo 'demo='.(config('quote_comparator.demo_offers')?'true':'false').' codes='.implode(',',config('quote_comparator.compare_wholesaler_codes'));"
echo '=== n8n demo_mode from N8nClient path ==='
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan tinker --execute="echo json_encode(config('quote_comparator.demo_offers'));"
