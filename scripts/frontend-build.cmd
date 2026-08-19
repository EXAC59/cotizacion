@echo off
setlocal
cd /d "%~dp0..\frontend"

echo === Build frontend (produccion) ===
echo Carpeta: %CD%
echo.

if not exist "node_modules\vite" (
  echo Instalando dependencias...
  call npm.cmd install
  if errorlevel 1 goto :error
)

call npm.cmd run build
if errorlevel 1 goto :error

echo.
echo Listo. Assets en public\spa\
exit /b 0

:error
echo.
echo ERROR: el build fallo.
pause
exit /b 1
