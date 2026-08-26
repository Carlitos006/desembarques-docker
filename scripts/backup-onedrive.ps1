[CmdletBinding()]
param(
    [string]$ProjectPath = "",
    [string]$BackupRoot = "",
    [switch]$SyncGitHub
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-BackupLog {
    param([Parameter(Mandatory)][string]$Message)

    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    if ($script:LogFile) {
        Add-Content -LiteralPath $script:LogFile -Value $line -Encoding utf8
    }
}

function Assert-LastExitCode {
    param([Parameter(Mandatory)][string]$Operation)

    if ($LASTEXITCODE -ne 0) {
        throw "$Operation termino con codigo $LASTEXITCODE."
    }
}

function New-DirectoryZip {
    param(
        [Parameter(Mandatory)][string]$SourceDirectory,
        [Parameter(Mandatory)][string]$DestinationPath
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path -LiteralPath $DestinationPath) {
        Remove-Item -LiteralPath $DestinationPath -Force
    }

    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $SourceDirectory,
        $DestinationPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
}

function New-WorkingTreeZip {
    param(
        [Parameter(Mandatory)][string]$RepositoryPath,
        [Parameter(Mandatory)][string]$DestinationPath
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $relativeFiles = @(& git -C $RepositoryPath ls-files --cached --others --exclude-standard)
    Assert-LastExitCode "La enumeracion de archivos Git"

    $archive = [System.IO.Compression.ZipFile]::Open(
        $DestinationPath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )

    try {
        foreach ($relativeFile in $relativeFiles) {
            if ([string]::IsNullOrWhiteSpace($relativeFile)) {
                continue
            }

            $platformPath = $relativeFile.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
            $fullPath = Join-Path $RepositoryPath $platformPath
            if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
                continue
            }

            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $fullPath,
                $relativeFile,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    }
    finally {
        $archive.Dispose()
    }
}

if ([string]::IsNullOrWhiteSpace($ProjectPath)) {
    $ProjectPath = Split-Path -Parent $PSScriptRoot
}

$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path
$ComposeFile = Join-Path $ProjectPath "compose.yml"
$UploadsPath = Join-Path $ProjectPath "storage\uploads"
$ImportsPath = Join-Path $ProjectPath "storage\aviso-imports"

if (-not (Test-Path -LiteralPath $ComposeFile -PathType Leaf)) {
    throw "No se encontro compose.yml en $ProjectPath."
}

if ([string]::IsNullOrWhiteSpace($BackupRoot)) {
    if ([string]::IsNullOrWhiteSpace($env:OneDriveCommercial)) {
        throw "No se encontro OneDriveCommercial. Inicia OneDrive empresarial o proporciona -BackupRoot."
    }
    $BackupRoot = Join-Path $env:OneDriveCommercial "DockerBackups\desembarques"
}

New-Item -ItemType Directory -Path $BackupRoot -Force | Out-Null
$BackupRoot = (Resolve-Path -LiteralPath $BackupRoot).Path

$timestamp = Get-Date -Format "yyyy-MM-dd_HHmmss"
$safeComputerName = $env:COMPUTERNAME -replace '[^A-Za-z0-9._-]', '-'
$backupName = "${timestamp}_${safeComputerName}"
$stagingFolder = Join-Path $BackupRoot ".in-progress-$backupName"
$finalFolder = Join-Path $BackupRoot $backupName
$statusFile = Join-Path $BackupRoot "last-backup-status-$safeComputerName.json"
$script:LogFile = $null
$containerDump = "/tmp/desembarques-$timestamp.sql"
$containerDumpCreated = $false

if (Test-Path -LiteralPath $finalFolder) {
    throw "Ya existe el destino $finalFolder."
}

New-Item -ItemType Directory -Path $stagingFolder -Force | Out-Null
$script:LogFile = Join-Path $stagingFolder "backup.log"

try {
    Write-BackupLog "Iniciando respaldo de $ProjectPath."

    & docker version --format "{{.Server.Version}}" | Out-Null
    Assert-LastExitCode "La comprobacion de Docker"

    Push-Location $ProjectPath
    try {
        $runningServices = @(& docker compose ps --services --status running)
        Assert-LastExitCode "La comprobacion de Docker Compose"
        if ($runningServices -notcontains "db") {
            throw "El servicio db no esta ejecutandose. Abre Docker Desktop e inicia el proyecto."
        }

        Write-BackupLog "Exportando la base MySQL."
        $dumpCommand = 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE" > "' + $containerDump + '"'
        & docker compose exec -T db sh -lc $dumpCommand
        Assert-LastExitCode "La exportacion de MySQL"
        $containerDumpCreated = $true

        $databaseFile = Join-Path $stagingFolder "database.sql"
        & docker compose cp "db:$containerDump" $databaseFile
        Assert-LastExitCode "La copia de database.sql"

        if ((Get-Item -LiteralPath $databaseFile).Length -le 0) {
            throw "database.sql quedo vacio."
        }
        if (-not (Get-Content -LiteralPath $databaseFile -Tail 10 | Select-String -Quiet '^-- Dump completed on ')) {
            throw "database.sql no contiene el cierre normal de mysqldump."
        }
        $tableCount = (Select-String -LiteralPath $databaseFile -Pattern '^CREATE TABLE ' | Measure-Object).Count
        if ($tableCount -le 0) {
            throw "database.sql no contiene tablas."
        }

        Write-BackupLog "Respaldando archivos operativos."
        if (Test-Path -LiteralPath $UploadsPath -PathType Container) {
            New-DirectoryZip -SourceDirectory $UploadsPath -DestinationPath (Join-Path $stagingFolder "uploads.zip")
        }
        if (Test-Path -LiteralPath $ImportsPath -PathType Container) {
            New-DirectoryZip -SourceDirectory $ImportsPath -DestinationPath (Join-Path $stagingFolder "aviso-imports.zip")
        }

        Write-BackupLog "Respaldando el codigo y el historial Git."
        New-WorkingTreeZip -RepositoryPath $ProjectPath -DestinationPath (Join-Path $stagingFolder "source-working-tree.zip")

        & git bundle create (Join-Path $stagingFolder "repository.bundle") --all
        Assert-LastExitCode "La creacion del paquete Git"

        $gitStatus = @(& git status --short --branch)
        Assert-LastExitCode "La lectura del estado Git"
        $gitStatus | Set-Content -LiteralPath (Join-Path $stagingFolder "git-status.txt") -Encoding utf8

        $gitCommit = (& git rev-parse HEAD).Trim()
        Assert-LastExitCode "La lectura del commit Git"
        $gitBranch = (& git branch --show-current).Trim()
        Assert-LastExitCode "La lectura de la rama Git"

        $uploadCount = if (Test-Path -LiteralPath $UploadsPath) {
            (Get-ChildItem -LiteralPath $UploadsPath -File -Recurse | Where-Object Name -ne ".gitkeep" | Measure-Object).Count
        } else { 0 }
        $importCount = if (Test-Path -LiteralPath $ImportsPath) {
            (Get-ChildItem -LiteralPath $ImportsPath -File -Recurse | Where-Object Name -ne ".gitkeep" | Measure-Object).Count
        } else { 0 }

        $manifest = [ordered]@{
            backup_version       = 1
            created_at           = (Get-Date).ToString("o")
            computer             = $env:COMPUTERNAME
            project_path         = $ProjectPath
            database_tables      = $tableCount
            upload_files         = $uploadCount
            historical_imports   = $importCount
            git_branch           = $gitBranch
            git_commit           = $gitCommit
            git_has_local_changes = (@($gitStatus | Where-Object { $_ -notmatch '^##' }).Count -gt 0)
            env_included         = $false
        }
        $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $stagingFolder "manifest.json") -Encoding utf8

        Write-BackupLog "Calculando firmas SHA-256."
        $checksumLines = foreach ($file in Get-ChildItem -LiteralPath $stagingFolder -File | Where-Object Name -notin @("SHA256SUMS.txt", "backup.log")) {
            $hash = Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256
            "{0}  {1}" -f $hash.Hash, $file.Name
        }
        $checksumLines | Set-Content -LiteralPath (Join-Path $stagingFolder "SHA256SUMS.txt") -Encoding ascii
    }
    finally {
        Pop-Location
        if ($containerDumpCreated) {
            & docker compose -f $ComposeFile exec -T db rm -f $containerDump | Out-Null
        }
    }

    Write-BackupLog "Respaldo completado correctamente."
    Move-Item -LiteralPath $stagingFolder -Destination $finalFolder

    [ordered]@{
        status       = "success"
        completed_at = (Get-Date).ToString("o")
        backup_path  = $finalFolder
    } | ConvertTo-Json | Set-Content -LiteralPath $statusFile -Encoding utf8

    Write-Host "Respaldo listo: $finalFolder"
}
catch {
    $message = $_.Exception.Message
    if ($script:LogFile -and (Test-Path -LiteralPath $stagingFolder)) {
        Write-BackupLog "ERROR: $message"
    }

    [ordered]@{
        status       = "failed"
        failed_at    = (Get-Date).ToString("o")
        error        = $message
        staging_path = $stagingFolder
    } | ConvertTo-Json | Set-Content -LiteralPath $statusFile -Encoding utf8

    throw
}

if ($SyncGitHub) {
    try {
        $syncScript = Join-Path $PSScriptRoot "sync-github.ps1"
        if (-not (Test-Path -LiteralPath $syncScript -PathType Leaf)) {
            throw "No se encontro $syncScript."
        }

        & $syncScript -ProjectPath $ProjectPath
    }
    catch {
        Write-Warning "El respaldo de OneDrive termino correctamente, pero la sincronizacion con GitHub fallo: $($_.Exception.Message)"
    }
}
