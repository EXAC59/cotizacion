@echo off
cd /d "%~dp0\.."
echo.
echo === Subir al VPS exacto.mx (paquete unico) ===
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0upload-vps-bundle.ps1"
pause
