[CmdletBinding()]
param(
    [string]$ProjectPath = "",
    [string]$Remote = "origin",
    [string]$Branch = "main",
    [string]$StatusRoot = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-SyncLog {
    param([Parameter(Mandatory)][string]$Message)

    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    if ($script:LogFile) {
        Add-Content -LiteralPath $script:LogFile -Value $line -Encoding utf8
    }
}

function Assert-GitExitCode {
    param([Parameter(Mandatory)][string]$Operation)

    if ($LASTEXITCODE -ne 0) {
        throw "$Operation termino con codigo $LASTEXITCODE."
    }
}

function Get-AheadBehind {
    param(
        [Parameter(Mandatory)][string]$RepositoryPath,
        [Parameter(Mandatory)][string]$RemoteBranch
    )

    $value = (& git -C $RepositoryPath rev-list --left-right --count "HEAD...$RemoteBranch").Trim()
    Assert-GitExitCode "La comparacion con GitHub"
    $parts = @($value -split '\s+')
    if ($parts.Count -ne 2) {
        throw "Git devolvio una comparacion inesperada: $value"
    }

    return [ordered]@{
        ahead  = [int]$parts[0]
        behind = [int]$parts[1]
    }
}

function Test-BlockedPath {
    param([Parameter(Mandatory)][string]$Path)

    $normalized = $Path.Replace('\', '/')
    $fileName = [System.IO.Path]::GetFileName($normalized)

    if ($fileName -like ".env*" -and $fileName -notin @(".env.example", ".env.sample")) {
        return $true
    }
    if ($normalized -match '(^|/)(backup|vendor|node_modules)(/|$)') {
        return $true
    }
    if ($normalized -match '(^|/)storage/(uploads|aviso-imports)(/|$)') {
        return $true
    }
    if ($normalized -match '(^|/)database\.sql$') {
        return $true
    }
    if ($normalized -match '(?i)\.(pem|key|pfx|p12|jks|keystore|zip|7z|rar|tar|tgz|gz|bak|dump)$') {
        return $true
    }

    return $false
}

function Write-SyncStatus {
    param(
        [Parameter(Mandatory)][string]$Status,
        [Parameter(Mandatory)][string]$Message,
        [string]$Commit = ""
    )

    [ordered]@{
        status       = $Status
        recorded_at  = (Get-Date).ToString("o")
        computer     = $env:COMPUTERNAME
        message      = $Message
        commit       = $Commit
    } | ConvertTo-Json | Set-Content -LiteralPath $script:StatusFile -Encoding utf8
}

if ([string]::IsNullOrWhiteSpace($ProjectPath)) {
    $ProjectPath = Split-Path -Parent $PSScriptRoot
}
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path

if ([string]::IsNullOrWhiteSpace($StatusRoot)) {
    if ([string]::IsNullOrWhiteSpace($env:OneDriveCommercial)) {
        throw "No se encontro OneDriveCommercial para guardar el estado de GitHub."
    }
    $StatusRoot = Join-Path $env:OneDriveCommercial "DockerBackups\desembarques"
}

New-Item -ItemType Directory -Path $StatusRoot -Force | Out-Null
$safeComputerName = $env:COMPUTERNAME -replace '[^A-Za-z0-9._-]', '-'
$script:LogFile = Join-Path $StatusRoot "github-sync-$safeComputerName.log"
$script:StatusFile = Join-Path $StatusRoot "last-github-sync-$safeComputerName.json"

try {
    Write-SyncLog "Iniciando sincronizacion segura con GitHub."

    $insideRepository = (& git -C $ProjectPath rev-parse --is-inside-work-tree).Trim()
    Assert-GitExitCode "La comprobacion del repositorio Git"
    if ($insideRepository -ne "true") {
        throw "$ProjectPath no es un repositorio Git."
    }

    $currentBranch = (& git -C $ProjectPath branch --show-current).Trim()
    Assert-GitExitCode "La lectura de la rama Git"
    if ($currentBranch -ne $Branch) {
        throw "La rama activa es '$currentBranch'. Se esperaba '$Branch'."
    }

    $gitDirectory = (& git -C $ProjectPath rev-parse --absolute-git-dir).Trim()
    Assert-GitExitCode "La ubicacion del directorio Git"
    foreach ($marker in @("MERGE_HEAD", "CHERRY_PICK_HEAD", "REVERT_HEAD", "rebase-apply", "rebase-merge")) {
        if (Test-Path -LiteralPath (Join-Path $gitDirectory $marker)) {
            throw "Existe una operacion Git pendiente ($marker). Debe resolverse manualmente."
        }
    }

    Write-SyncLog "Consultando cambios remotos."
    & git -C $ProjectPath fetch $Remote $Branch --prune
    Assert-GitExitCode "La consulta de GitHub"

    $remoteBranch = "$Remote/$Branch"
    $comparison = Get-AheadBehind -RepositoryPath $ProjectPath -RemoteBranch $remoteBranch
    $workingChanges = @(& git -C $ProjectPath status --porcelain=v1 --untracked-files=all)
    Assert-GitExitCode "La lectura de cambios locales"

    if ($workingChanges.Count -gt 0) {
        if ($comparison.behind -gt 0) {
            throw "GitHub contiene $($comparison.behind) cambio(s) nuevos. Ejecuta git pull antes de la sincronizacion automatica."
        }

        Write-SyncLog "Preparando cambios locales."
        & git -C $ProjectPath add -A
        Assert-GitExitCode "La preparacion de cambios"

        $stagedPaths = @(& git -C $ProjectPath diff --cached --name-only --diff-filter=ACMR)
        Assert-GitExitCode "La revision de rutas preparadas"

        $blockedPaths = @($stagedPaths | Where-Object { Test-BlockedPath -Path $_ })
        if ($blockedPaths.Count -gt 0) {
            throw "Se bloquearon rutas sensibles o de respaldo: $($blockedPaths -join ', ')"
        }

        $largePaths = @()
        foreach ($path in $stagedPaths) {
            $fullPath = Join-Path $ProjectPath $path.Replace('/', [System.IO.Path]::DirectorySeparatorChar)
            if ((Test-Path -LiteralPath $fullPath -PathType Leaf) -and (Get-Item -LiteralPath $fullPath).Length -gt 10MB) {
                $largePaths += $path
            }
        }
        if ($largePaths.Count -gt 0) {
            throw "Se bloquearon archivos mayores de 10 MB: $($largePaths -join ', ')"
        }

        $stagedDiff = @(& git -C $ProjectPath diff --cached --no-ext-diff --unified=0 -- . ':(exclude)scripts/sync-github.ps1') -join "`n"
        Assert-GitExitCode "La revision del contenido preparado"

        $secretPatterns = @(
            '-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----',
            '\bgithub_pat_[A-Za-z0-9_]{30,}\b',
            '\bgh[pousr]_[A-Za-z0-9]{30,}\b',
            '\bAKIA[0-9A-Z]{16}\b',
            '\bsk-(proj-)?[A-Za-z0-9_-]{20,}\b',
            '\bxox[baprs]-[A-Za-z0-9-]{10,}\b',
            '(?im)^\+(?!\+\+).{0,120}\b(password|passwd|secret|api[_-]?key|access[_-]?token|private[_-]?key)\b\s*[:=]\s*["''][^"''$\s][^"'']{7,}["'']'
        )
        foreach ($pattern in $secretPatterns) {
            if ([regex]::IsMatch($stagedDiff, $pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
                throw "La revision preventiva detecto contenido que podria ser un secreto. Revisa los cambios manualmente."
            }
        }

        & git -C $ProjectPath diff --cached --check
        Assert-GitExitCode "La validacion de los cambios preparados"

        & git -C $ProjectPath diff --cached --quiet
        $diffExitCode = $LASTEXITCODE
        if ($diffExitCode -eq 1) {
            $commitMessage = "chore: daily sync {0} [{1}]" -f (Get-Date -Format "yyyy-MM-dd HH:mm"), $safeComputerName
            & git -C $ProjectPath commit -m $commitMessage
            Assert-GitExitCode "La creacion del commit diario"
            Write-SyncLog "Commit diario creado."
        }
        elseif ($diffExitCode -ne 0) {
            throw "No fue posible determinar si existen cambios preparados."
        }
    }

    $comparison = Get-AheadBehind -RepositoryPath $ProjectPath -RemoteBranch $remoteBranch
    if ($comparison.behind -gt 0 -and $comparison.ahead -eq 0) {
        Write-SyncLog "Actualizando la copia local mediante avance directo."
        & git -C $ProjectPath merge --ff-only $remoteBranch
        Assert-GitExitCode "La actualizacion local"
        $comparison = Get-AheadBehind -RepositoryPath $ProjectPath -RemoteBranch $remoteBranch
    }
    elseif ($comparison.behind -gt 0 -and $comparison.ahead -gt 0) {
        throw "La rama local y GitHub tienen historiales distintos. Se requiere integracion manual."
    }

    if ($comparison.ahead -gt 0) {
        Write-SyncLog "Enviando $($comparison.ahead) commit(s) a GitHub."
        & git -C $ProjectPath push $Remote "HEAD:$Branch"
        Assert-GitExitCode "El envio a GitHub"
    }
    else {
        Write-SyncLog "GitHub ya estaba actualizado."
    }

    $finalCommit = (& git -C $ProjectPath rev-parse HEAD).Trim()
    Assert-GitExitCode "La lectura del commit final"
    Write-SyncStatus -Status "success" -Message "GitHub actualizado correctamente." -Commit $finalCommit
    Write-SyncLog "Sincronizacion terminada en $finalCommit."
}
catch {
    $message = $_.Exception.Message
    Write-SyncStatus -Status "failed" -Message $message
    Write-SyncLog "ERROR: $message"
    throw
}
