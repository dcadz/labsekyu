@echo off
:: schedule_cron.bat
:: This script creates a Windows Scheduled Task to run cron_absent.php every 5 minutes.
:: Make sure to run this as Administrator.

set PHP_EXEC=C:\xampp\php\php.exe
set SCRIPT_PATH=C:\xampp\htdocs\labsekyu2\cron_absent.php

schtasks /create /tn "LabsekyuCronAbsent" /tr "%PHP_EXEC% %SCRIPT_PATH%" /sc minute /mo 5 /F

echo.
echo Scheduled task "LabsekyuCronAbsent" created successfully.
echo It will run cron_absent.php every 5 minutes.
pause
