@echo off
setlocal EnableDelayedExpansion

set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-*") do (
  if exist "%%D\php.exe" set "PHP=%%D\php.exe"
)

if not defined PHP (
  echo ERROR: No se encontro php.exe en C:\laragon\bin\php\
  exit /b 1
)

"%PHP%" %*
