# Registers the "OutlineMediaCRM-Monitor" scheduled task: runs cron\run-task.cmd every 5 minutes, forever,
# on battery or mains, whether or not the user is logged in (when run elevated), never overlapping.
# Usage:  powershell -NoProfile -ExecutionPolicy Bypass -File cron\register-windows-task.ps1
$ErrorActionPreference = 'Stop'
$taskName = 'OutlineMediaCRM-Monitor'
$cronDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$wrapper  = Join-Path $cronDir 'run-task.cmd'
if (-not (Test-Path $wrapper)) { throw "Wrapper not found: $wrapper" }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
$user = "$env:USERDOMAIN\$env:USERNAME"

$action   = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c "' + $wrapper + '"') -WorkingDirectory $cronDir
$trigger  = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
            -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 20) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) `
            -Compatibility Win8 -Hidden:$false
$settings.DisallowStartIfOnBatteries = $false
$settings.StopIfGoingOnBatteries = $false

# S4U = "run whether user is logged on or not" without storing a password (needs an elevated shell to register)
if ($isAdmin) {
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Limited
    $mode = 'runs whether or not you are logged in'
} else {
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
    $mode = 'runs while you are logged in (run this script as Administrator for "whether or not logged in")'
}

Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue | Out-Null
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
    -Description 'Outline Media CRM – automatic website / page / form monitoring every 5 minutes (cron\run-task.cmd)' | Out-Null

# Also fire at system start-up (2 min delay) so monitoring resumes after a reboot even before anyone logs in
try {
    $boot = New-ScheduledTaskTrigger -AtStartup
    $boot.Delay = 'PT2M'
    $boot.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)).Repetition
    Set-ScheduledTask -TaskName $taskName -Trigger @($trigger, $boot) | Out-Null
} catch { Write-Host "Start-up trigger not added: $($_.Exception.Message)" }

Start-ScheduledTask -TaskName $taskName
Write-Host "Task '$taskName' registered: $mode."
Write-Host "Every 5 minutes it runs $wrapper (log: logs\task-scheduler.log)."
