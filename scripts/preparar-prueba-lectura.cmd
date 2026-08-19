@echo off

cd /d "%~dp0.."

echo.

echo === Prueba lectura (Paso 2) - Cotizacion ===

echo Carpeta: %CD%

echo.



powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0preparar-prueba-lectura.ps1"

set ERR=%ERRORLEVEL%



echo.

if %ERR% neq 0 (

  echo Termino con error %ERR%.

) else (

  echo Termino OK.

)

pause

exit /b %ERR%

