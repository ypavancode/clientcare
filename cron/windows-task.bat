@echo off
REM ---------------------------------------------------------------------------
REM  Registers (or re-registers) the Windows Task Scheduler job "OutlineMediaCRM-Monitor" that runs the
REM  CRM monitoring every 5 minutes on a local XAMPP machine – whether or not anyone is logged in or the
REM  browser is open. Run this file once (right-click -> Run as administrator for "run whether user is
REM  logged on or not"; without admin rights the task still runs every 5 minutes while you are logged in).
REM
REM  What the task does:  cron\run-task.cmd  ->  starts MySQL if needed  ->  php cron\run-all.php
REM  Remove with:         schtasks /Delete /TN "OutlineMediaCRM-Monitor" /F
REM  Log:                 logs\task-scheduler.log  and  logs\cron.log
REM ---------------------------------------------------------------------------
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0register-windows-task.ps1"
pause
