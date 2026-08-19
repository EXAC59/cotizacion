#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo ">> Instalando dependencias de Composer..."
    composer install --no-interaction --prefer-dist
fi

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction
fi

php artisan config:clear --no-interaction
php artisan route:clear --no-interaction

echo ">> Esperando PostgreSQL..."
until php -r "
    try {
        new PDO(
            'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE'),
            getenv('DB_USERNAME'),
            getenv('DB_PASSWORD')
        );
        exit(0);
    } catch (Throwable \$e) {
        exit(1);
    }
" 2>/dev/null; do
    sleep 2
done

php artisan migrate --force --no-interaction

exec "$@"
