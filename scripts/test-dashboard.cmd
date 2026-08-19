@echo off
setlocal
cd /d "%~dp0\.."

echo === Tests: DashboardAnalyticsTest ===
call "%~dp0laragon-php.cmd" artisan test --filter=DashboardAnalyticsTest
exit /b %ERRORLEVEL%
