@echo off
cd /d "%~dp0..\frontend"
echo.
echo === Frontend React (Vite) ===
echo Carpeta: %CD%
echo.

if not exist "node_modules\vite" (
  echo Instalando dependencias (primera vez)...
  call npm.cmd install
  if errorlevel 1 (
    echo ERROR: npm install fallo.
    pause
    exit /b 1
  )
)

echo Abriendo http://localhost:5173
echo Deja esta ventana abierta. Ctrl+C para detener.
echo.
call npm.cmd run dev
pause

