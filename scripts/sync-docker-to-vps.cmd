@echo off
REM Sincroniza archivos Docker al VPS (evita ExecutionPolicy de PowerShell).
cd /d "%~dp0.."
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync-docker-to-vps.ps1" %*
if errorlevel 1 pause
