@echo off

cd /d "%~dp0"

echo.

echo === Probar lectura con n8n ===

echo Carpeta: %CD%

echo.



set "DOCKER=C:\Program Files\Docker\Docker\resources\bin\docker.exe"

if exist "%DOCKER%" (

  "%DOCKER%" ps --filter name=cotizacion_n8n --format "{{.Status}}" 2>nul | findstr /i "Up" >nul

  if errorlevel 1 (

    echo Levantando Docling + n8n...

    call "%~dp0scripts\lectura-up.cmd"

  ) else (

    echo Docker: cotizacion_n8n ya esta corriendo.

  )

) else (

  echo AVISO: Docker no encontrado. Abre Docker Desktop manualmente.

)



echo.

echo Esperando n8n en http://localhost:5678 ...

timeout /t 5 /nobreak >nul



if "%~1"=="" (

  set "PDF=%CD%\storage\app\private\lectura\ejemplo-prueba.pdf"

  if not exist "%PDF%" (

    echo Descargando PDF de prueba...

    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\preparar-prueba-lectura.ps1"

    if errorlevel 1 exit /b 1

  )

) else (

  set "PDF=%~1"

)



echo Archivo: %PDF%

echo.

echo IMPORTANTE: en n8n activa el workflow (interruptor verde) y Save.

echo   http://localhost:5678

echo.

call "%~dp0scripts\test-solicitud-lectura.cmd" "%PDF%" n8n

exit /b %ERRORLEVEL%

