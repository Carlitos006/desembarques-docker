[CmdletBinding()]
param(
    [ValidatePattern('^([01]\d|2[0-3]):[0-5]\d$')]
    [string]$DailyTime = "19:00",
    [string]$TaskName = "Desembarques - Respaldo diario"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$backupScript = Join-Path $PSScriptRoot "backup-onedrive.ps1"
if (-not (Test-Path -LiteralPath $backupScript -PathType Leaf)) {
    throw "No se encontro $backupScript."
}

$oneDriveRoot = $env:OneDriveCommercial
if ([string]::IsNullOrWhiteSpace($oneDriveRoot)) {
    throw "No se encontro OneDriveCommercial. Inicia OneDrive empresarial antes de instalar la tarea."
}

$time = [datetime]::ParseExact($DailyTime, "HH:mm", [System.Globalization.CultureInfo]::InvariantCulture)
$powerShellExecutable = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"
if (-not (Test-Path -LiteralPath $powerShellExecutable -PathType Leaf)) {
    throw "No se encontro Windows PowerShell en $powerShellExecutable."
}
$arguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f $backupScript

$action = New-ScheduledTaskAction `
    -Execute $powerShellExecutable `
    -Argument $arguments `
    -WorkingDirectory (Split-Path -Parent $PSScriptRoot)

$trigger = New-ScheduledTaskTrigger -Daily -At $time
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew

$principal = New-ScheduledTaskPrincipal `
    -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Respalda diariamente la base MySQL, los archivos operativos y el codigo de Desembarques en OneDrive empresarial." `
    -Force | Out-Null

Write-Host "Tarea instalada: $TaskName"
Write-Host "Horario diario: $DailyTime"
Write-Host "Si el equipo esta apagado, Windows la ejecutara al volver a iniciar sesion."
