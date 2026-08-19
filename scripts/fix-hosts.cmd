@echo off
:: Agrega cotizacion.test al archivo hosts (requiere ejecutar como Administrador)
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo Ejecuta este archivo con clic derecho -^> "Ejecutar como administrador"
    echo.
    pause
    exit /b 1
)

findstr /C:"cotizacion.test" %SystemRoot%\System32\drivers\etc\hosts >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] cotizacion.test ya esta en hosts
) else (
    echo 127.0.0.1 cotizacion.test>> %SystemRoot%\System32\drivers\etc\hosts
    echo [OK] Agregado: 127.0.0.1 cotizacion.test
)

echo.
echo Prueba: http://cotizacion.test/spa/
pause
