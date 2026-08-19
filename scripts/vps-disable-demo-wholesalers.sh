#!/usr/bin/env bash
# Desactiva ofertas demo e Ingram/Exel/etc. en VPS — deja solo CT activo.
set -euo pipefail
cd /var/www/cotizacion

grep -q '^WHOLESALER_DEMO_OFFERS=' .env \
  && sed -i 's|^WHOLESALER_DEMO_OFFERS=.*|WHOLESALER_DEMO_OFFERS=false|' .env \
  || echo 'WHOLESALER_DEMO_OFFERS=false' >> .env

grep -q '^WHOLESALER_COMPARE_CODES=' .env \
  && sed -i 's|^WHOLESALER_COMPARE_CODES=.*|WHOLESALER_COMPARE_CODES=CT|' .env \
  || echo 'WHOLESALER_COMPARE_CODES=CT' >> .env

docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan config:clear

docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$inactive = App\Models\Wholesaler::query()->where('code', '!=', 'CT')->update(['active' => false]);
\$active = App\Models\Wholesaler::query()->where('code', 'CT')->update(['active' => true]);
echo \"inactive_others=\$inactive ct_active=\$active\\n\";
"

docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api \
  php artisan comparator:national-warehouses --force

echo 'DEMO_WHOlesalers_DISABLED_OK'
