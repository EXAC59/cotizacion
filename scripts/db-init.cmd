@echo off
setlocal
cd /d "%~dp0\.."

echo === PostgreSQL: migraciones Laravel ===
php artisan migrate --force
if errorlevel 1 goto :error

echo.
echo === PostgreSQL: esquema de dominio ===
php artisan db:init-domain
if errorlevel 1 goto :error

echo.
echo === PostgreSQL: catalogo mayoristas ===
php artisan wholesalers:sync-catalog
if errorlevel 1 goto :error

echo.
echo === Verificacion ===
php artisan db:show
if errorlevel 1 goto :error

echo.
echo Listo. APP_DEMO_MODE=false en .env y reinicia Laragon si hace falta.
exit /b 0

:error
echo.
echo Fallo la inicializacion de base de datos.
exit /b 1
