@echo off
cd /d "%~dp0.."
set "DOCKER=C:\Program Files\Docker\Docker\resources\bin\docker.exe"

if not exist "%DOCKER%" (
  echo ERROR: No se encontro docker.exe en:
  echo   %DOCKER%
  echo Instala Docker Desktop y vuelve a intentar.
  pause
  exit /b 1
)

echo Docker: %DOCKER%
echo.

"%DOCKER%" info >nul 2>&1
if errorlevel 1 (
  echo ERROR: Abre Docker Desktop y espera "Engine running".
  pause
  exit /b 1
)

echo Levantando docling + n8n (primera vez descarga imagenes grandes)...
"%DOCKER%" compose -f docker-compose.lectura.yml up -d

if errorlevel 1 (
  echo Fallo docker compose.
  pause
  exit /b 1
)

echo.
echo Listo:
echo   http://localhost:5001/ui   Docling
echo   http://localhost:5678      n8n
echo.
pause
