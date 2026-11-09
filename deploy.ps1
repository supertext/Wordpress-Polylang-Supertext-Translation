#Requires -Version 5.1
<#
.SYNOPSIS
    Commits to GitHub, then deploys the Supertext plugin to the demo server via SFTP.
.DESCRIPTION
    1. Stages all changes, commits with an auto-generated message, and pushes to origin/main.
    2. Syncs the plugin directory to the remote WordPress plugins folder via WinSCP SFTP,
       uploading only what the plugin needs at runtime (the same set as demo/Dockerfile:
       supertext-polylang.php, includes/, languages/, assets/, LICENSE). Repository-only
       files stay local — git, CI, tests, docs, demo/, scripts/, the deploy scripts and the
       Markdown docs: the remote plugin folder is served over HTTP, so anything uploaded
       there is public.
    3. Patches the server's Polylang Pro (Factory.php) to expose the `pll_mt_services`
       filter so Supertext can register as a machine-translation service. Idempotent and
       self-healing across Polylang updates; backs up the original to Factory.php.bak.
    Requires WinSCP to be installed (https://winscp.net).
.PARAMETER CommitMessage
    Optional commit message. Auto-generated from changed files if omitted.
#>
param(
    [string]$CommitMessage = ''
)

function Get-AutoCommitMessage {
    $lines = git status --short 2>$null | Where-Object { $_ -match '\S' }
    if (-not $lines) { return $null }

    # Map file path fragments to readable labels (first match wins, order matters)
    $labelMap = [ordered]@{
        'includes/Machine_Translation/Client'   = 'MT client'
        'includes/Machine_Translation/Service'  = 'MT service'
        'includes/Machine_Translation/Settings' = 'MT settings'
        'includes/Machine_Translation'          = 'MT integration'
        'supertext-polylang.php'                = 'main plugin file'
        'uninstall.php'                         = 'uninstall handler'
        'languages/'                            = 'translations'
        'README'                                = 'docs'
        'deploy'                                = 'deploy config'
    }

    $labels   = [System.Collections.Generic.List[string]]::new()
    $hasAdd   = $false
    $hasMod   = $false
    $hasDel   = $false

    foreach ($line in $lines) {
        $code = $line.Substring(0, 2).Trim()
        $file = $line.Substring(3).Trim()

        if ($code -match '[A?]') { $hasAdd = $true }
        if ($code -match 'M')    { $hasMod = $true }
        if ($code -match 'D')    { $hasDel = $true }

        foreach ($entry in $labelMap.GetEnumerator()) {
            if ($file -like "*$($entry.Key)*" -and $labels -notcontains $entry.Value) {
                $labels.Add($entry.Value)
                break
            }
        }
    }

    # Fallback: use raw filenames if nothing matched
    if ($labels.Count -eq 0) {
        $lines | ForEach-Object { $_.Substring(3).Trim() } |
            Select-Object -First 3 |
            ForEach-Object { $labels.Add($_) }
    }

    $verb = if     ($hasDel -and -not $hasAdd -and -not $hasMod) { 'Remove' }
            elseif ($hasAdd -and -not $hasMod -and -not $hasDel) { 'Add' }
            else                                                  { 'Update' }

    return "$verb $($labels -join ', ')"
}

# Runs a WinSCP script (same CLI approach as the sync step) and returns its exit code.
function Invoke-WinScpScript {
    param(
        [Parameter(Mandatory)] [string]$WinScp,
        [Parameter(Mandatory)] [string[]]$ScriptLines,
        [Parameter(Mandatory)] [string]$LogPath
    )
    $tmp = [System.IO.Path]::GetTempFileName() + '.winscp'
    Set-Content -Path $tmp -Value ($ScriptLines -join "`r`n") -Encoding UTF8
    try {
        & $WinScp /script="$tmp" /log="$LogPath" | Out-Null
        return $LASTEXITCODE
    }
    finally {
        Remove-Item -Path $tmp -Force -ErrorAction SilentlyContinue
    }
}

<#
.SYNOPSIS
    Ensures Polylang Pro on the server exposes the `pll_mt_services` filter so the
    Supertext service can register itself.
.DESCRIPTION
    Idempotent (uses the same WinSCP CLI approach as the sync step): downloads
    Polylang's Machine_Translation/Factory.php; if it does not yet contain the
    filter, backs it up to Factory.php.bak and replaces `return self::SERVICES;`
    with `return apply_filters( 'pll_mt_services', self::SERVICES );`, then uploads
    it back. Safe to run on every deploy — it re-applies the patch after a Polylang
    update (which would otherwise overwrite it). Never aborts the deploy on failure.
#>
function Update-PolylangFactoryPatch {
    param(
        [Parameter(Mandatory)] [string]$WinScp,
        [Parameter(Mandatory)] [string]$Server,
        [Parameter(Mandatory)] [string]$Username,
        [Parameter(Mandatory)] [string]$Password,
        [Parameter(Mandatory)] [string]$PolylangDir,
        [Parameter(Mandatory)] [string]$LogPath
    )

    $open   = "open sftp://${Username}:${Password}@${Server}/ -hostkey=*"
    $tmpDir = Join-Path $env:TEMP ('pllpatch_' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null
    $localFile = Join-Path $tmpDir 'Factory.php'

    try {
        # Factory.php lives under src/modules/ (Polylang 3.8+) or modules/ (3.7).
        $candidates = @(
            "$PolylangDir/src/modules/Machine_Translation/Factory.php",
            "$PolylangDir/modules/Machine_Translation/Factory.php"
        )

        $remotePath = $null
        foreach ($c in $candidates) {
            if (Test-Path $localFile) { Remove-Item $localFile -Force }
            $rc = Invoke-WinScpScript -WinScp $WinScp -LogPath $LogPath -ScriptLines @(
                'option batch abort', 'option confirm off', $open,
                "get ""$c"" ""$localFile""", 'exit'
            )
            if ($rc -eq 0 -and (Test-Path $localFile)) { $remotePath = $c; break }
        }
        if (-not $remotePath) {
            Write-Warning "Polylang Factory.php not found under $PolylangDir — skipping patch."
            return
        }

        $content = [System.IO.File]::ReadAllText($localFile)

        if ($content -match 'pll_mt_services') {
            Write-Host "Polylang already patched (pll_mt_services filter present)." -ForegroundColor DarkGray
            return
        }
        if ($content -notmatch 'return self::SERVICES;') {
            Write-Warning "Expected 'return self::SERVICES;' not found in Factory.php — Polylang internals may have changed. Skipping patch."
            return
        }

        # Back up the pristine file (overwriting an older .bak with pristine content is harmless).
        $rcBak = Invoke-WinScpScript -WinScp $WinScp -LogPath $LogPath -ScriptLines @(
            'option batch abort', 'option confirm off', $open,
            "put ""$localFile"" ""$remotePath.bak""", 'exit'
        )
        if ($rcBak -ne 0) { Write-Warning "Could not write Factory.php.bak (continuing)." }

        $patched = $content -replace 'return self::SERVICES;', "return apply_filters( 'pll_mt_services', self::SERVICES );"

        # Write UTF-8 WITHOUT BOM — a BOM before <?php breaks PHP ("headers already sent").
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($localFile, $patched, $utf8NoBom)

        $rcPut = Invoke-WinScpScript -WinScp $WinScp -LogPath $LogPath -ScriptLines @(
            'option batch abort', 'option confirm off', $open,
            "put ""$localFile"" ""$remotePath""", 'exit'
        )
        if ($rcPut -eq 0) {
            Write-Host "Patched Polylang Factory.php (added pll_mt_services filter)." -ForegroundColor Green
        } else {
            Write-Warning "Failed to upload patched Factory.php (exit $rcPut) — check deploy.log."
        }
    }
    catch {
        Write-Warning "Polylang patch step failed: $($_.Exception.Message). The plugin was still deployed; apply the patch manually if needed."
    }
    finally {
        if (Test-Path $tmpDir) { Remove-Item $tmpDir -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

$ErrorActionPreference = 'Stop'

# --- Config -------------------------------------------------------------------
$LocalDir = $PSScriptRoot
# ------------------------------------------------------------------------------

# Load server credentials from local file (gitignored)
$CredFile = Join-Path $LocalDir 'deploy.local.ps1'
if (-not (Test-Path $CredFile)) {
    Write-Error "deploy.local.ps1 not found. Copy deploy.local.ps1.example, fill in your credentials, and re-run."
    exit 1
}
. $CredFile

foreach ($var in @('Server', 'Username', 'Password', 'RemotePluginDir')) {
    if (-not (Get-Variable -Name $var -ValueOnly -ErrorAction SilentlyContinue)) {
        Write-Error "deploy.local.ps1 is missing `$$var. See deploy.local.ps1.example."
        exit 1
    }
}

Set-Location $LocalDir

# ── Step 1: Git commit & push ─────────────────────────────────────────────────
Write-Host "Checking git status..." -ForegroundColor Cyan

$gitStatus = git status --porcelain 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "git status failed. Is this a git repository?"
    exit 1
}

if ($gitStatus) {
    if (-not $CommitMessage) {
        $CommitMessage = Get-AutoCommitMessage
        if (-not $CommitMessage) {
            $CommitMessage = "Update plugin ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"
        }
    }

    Write-Host "Staging all changes..." -ForegroundColor Cyan
    git add --all
    if ($LASTEXITCODE -ne 0) { Write-Error "git add failed."; exit 1 }

    Write-Host "Committing: $CommitMessage" -ForegroundColor Cyan
    git commit -m $CommitMessage
    if ($LASTEXITCODE -ne 0) { Write-Error "git commit failed."; exit 1 }
} else {
    Write-Host "Nothing to commit — working tree clean." -ForegroundColor DarkGray
}

Write-Host "Pushing to origin/main..." -ForegroundColor Cyan
git push origin main
if ($LASTEXITCODE -ne 0) { Write-Error "git push failed."; exit 1 }
Write-Host "GitHub up to date." -ForegroundColor Green

# ── Step 2: Locate WinSCP ─────────────────────────────────────────────────────
$WinScpCandidates = @(
    'winscp.com',
    'C:\Program Files (x86)\WinSCP\WinSCP.com',
    'C:\Program Files\WinSCP\WinSCP.com'
)
$WinScp = $null
foreach ($candidate in $WinScpCandidates) {
    if (Get-Command $candidate -ErrorAction SilentlyContinue) {
        $WinScp = $candidate; break
    }
    if (Test-Path $candidate) {
        $WinScp = $candidate; break
    }
}

if (-not $WinScp) {
    Write-Error "WinSCP not found. Install it from https://winscp.net/eng/download.php then re-run."
    exit 1
}

Write-Host "Using WinSCP: $WinScp" -ForegroundColor Cyan

# ── Step 3: SFTP sync ─────────────────────────────────────────────────────────
$LocalDirUnix = $LocalDir.Replace('\', '/')

# Ensure the remote plugin directory exists (synchronize won't create the target root
# on a first deploy). Tolerate "already exists" by ignoring the exit code.
$null = Invoke-WinScpScript -WinScp $WinScp -LogPath "$LocalDir\deploy.log" -ScriptLines @(
    'option batch abort', 'option confirm off',
    "open sftp://${Username}:${Password}@${Server}/ -hostkey=*",
    "mkdir ""$RemotePluginDir""", 'exit'
)

$ScriptContent = @"
option batch abort
option confirm off
open sftp://${Username}:${Password}@${Server}/ -hostkey=*
synchronize remote -delete -criteria=either -filemask="|.git\;.github\;tests\;docs\;demo\;scripts\;.gitignore;deploy.ps1;deploy.local.ps1;deploy.local.ps1.example;deploy.log;supertext-debug.log;README.md;HANDOFF.md;SECURITY.md;phpstan.neon;phpstan-baseline.neon" "$LocalDirUnix" "$RemotePluginDir"
exit
"@

$TempScript = [System.IO.Path]::GetTempFileName() + '.winscp'
Set-Content -Path $TempScript -Value $ScriptContent -Encoding UTF8

try {
    Write-Host "Deploying to $Server$RemotePluginDir ..." -ForegroundColor Cyan
    & $WinScp /script="$TempScript" /log="$LocalDir\deploy.log"

    if ($LASTEXITCODE -ne 0) {
        Write-Error "WinSCP exited with code $LASTEXITCODE — check deploy.log for details."
        exit $LASTEXITCODE
    }

    Write-Host "Deploy complete." -ForegroundColor Green
}
finally {
    Remove-Item -Path $TempScript -Force -ErrorAction SilentlyContinue
}

# ── Step 4: Patch Polylang so Supertext can register as an MT service ──────────
# Derive the Polylang folder from the plugins directory (sibling of our plugin),
# unless deploy.local.ps1 sets $PolylangDir explicitly.
if (-not (Get-Variable -Name 'PolylangDir' -ValueOnly -ErrorAction SilentlyContinue)) {
    $pluginsDir  = $RemotePluginDir.TrimEnd('/')
    $pluginsDir  = $pluginsDir.Substring(0, $pluginsDir.LastIndexOf('/'))
    $PolylangDir = "$pluginsDir/polylang-pro"
}

Write-Host "Ensuring Polylang exposes the pll_mt_services filter ($PolylangDir) ..." -ForegroundColor Cyan
Update-PolylangFactoryPatch -WinScp $WinScp -Server $Server -Username $Username -Password $Password `
    -PolylangDir $PolylangDir -LogPath "$LocalDir\deploy.log"
