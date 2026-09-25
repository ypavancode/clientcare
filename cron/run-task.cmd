@echo off
REM ---------------------------------------------------------------------------
REM  Windows / XAMPP wrapper run by Task Scheduler every 5 minutes (see windows-task.bat).
REM  1. Makes sure MySQL is running (XAMPP does not start it automatically after a reboot),
REM  2. runs cron\run-all.php with the XAMPP PHP binary,
REM  3. appends everything to logs\task-scheduler.log so failures are visible.
REM  On a Linux / cPanel server this file is not used – see the cron lines in Settings -> Cron Jobs.
REM ---------------------------------------------------------------------------
setlocal
set "CRON_DIR=%~dp0"
set "ROOT=%CRON_DIR%.."
set "PHP=%ROOT%\..\..\php\php.exe"
if not exist "%PHP%" set "PHP=E:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=C:\xampp\php\php.exe"
set "LOG=%ROOT%\logs\task-scheduler.log"

REM --- keep the log from growing forever (~1 MB) ---
for %%A in ("%LOG%") do if %%~zA GTR 1048576 del /q "%LOG%"

echo [%date% %time%] task started (user %USERNAME%)>>"%LOG%"

REM --- start MySQL if it is not running (local XAMPP only) ---
for %%D in ("%ROOT%\..\..\mysql\bin" "E:\xampp\mysql\bin" "C:\xampp\mysql\bin") do (
  if exist "%%~D\mysqld.exe" (
    tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I "mysqld.exe" >NUL
    if errorlevel 1 (
      echo [%date% %time%] MySQL is not running - starting %%~D\mysqld.exe>>"%LOG%"
      start "" /B "%%~D\mysqld.exe" --defaults-file="%%~D\my.ini" --standalone
      timeout /t 6 /nobreak >NUL
    )
    goto :mysql_done
  )
)
:mysql_done

cd /d "%CRON_DIR%"
"%PHP%" "%CRON_DIR%run-all.php" >>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
echo [%date% %time%] task finished with exit code %RC%>>"%LOG%"
endlocal & exit /b %RC%
