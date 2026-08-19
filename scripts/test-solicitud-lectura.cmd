@echo off

cd /d "%~dp0.."

echo.

echo === Test API solicitudes/lectura ===

echo.



if "%~1"=="" (

  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test-solicitud-lectura.ps1"

) else if /i "%~2"=="n8n" (

  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test-solicitud-lectura.ps1" -FilePath "%~1" -Via n8n

) else (

  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0test-solicitud-lectura.ps1" -FilePath "%~1"

)



set ERR=%ERRORLEVEL%

echo.

if %ERR% neq 0 (echo Termino con error %ERR%.) else (echo Termino OK.)

pause

exit /b %ERR%

