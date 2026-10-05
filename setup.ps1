# ============================================================
# setup.ps1 - one-time local setup for PLP Admissions (Windows)
#   1. Enables the PostgreSQL extensions in php.ini
#   2. Creates .env from .env.example if missing
#   3. Verifies everything
# Safe to run more than once.
# Run:  double-click setup.bat   (or)   .\setup.ps1
# ============================================================

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Ok($m)   { Write-Host "  [OK]   $m" -ForegroundColor Green }
function Warn($m) { Write-Host "  [WARN] $m" -ForegroundColor Yellow }
function Fail($m) { Write-Host "  [FAIL] $m" -ForegroundColor Red; exit 1 }

Write-Host "`nPLP Admissions - local setup`n"

# --- 1. Find PHP ------------------------------------------------
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd) {
    Fail "PHP was not found in PATH. Install PHP 8.2+ (or XAMPP) and add it to PATH, then run this again."
}
$phpVersion = (& php -r "echo PHP_VERSION;")
Ok "PHP $phpVersion found at $($phpCmd.Source)"
if ([version]($phpVersion -replace '[^\d\.].*$','') -lt [version]'8.0') {
    Warn "PHP 8.0+ is recommended (the project is developed on 8.2)."
}

# --- 2. Locate or create php.ini --------------------------------
$phpDir = Split-Path $phpCmd.Source
$iniLine = (& php --ini) | Where-Object { $_ -match 'Loaded Configuration File' }
$iniPath = ($iniLine -replace '^.*:\s+(?=[A-Za-z]:|\\)', '').Trim()

if (-not $iniPath -or $iniPath -match '\(none\)' -or -not (Test-Path $iniPath)) {
    $dev = Join-Path $phpDir 'php.ini-development'
    if (-not (Test-Path $dev)) { Fail "No php.ini and no php.ini-development found in $phpDir. Create php.ini manually." }
    $iniPath = Join-Path $phpDir 'php.ini'
    Copy-Item $dev $iniPath
    Ok "Created php.ini from php.ini-development"
} else {
    Ok "Using $iniPath"
}

# --- 3. Enable extensions ---------------------------------------
$utf8 = New-Object System.Text.UTF8Encoding($false)
try {
    $text = [System.IO.File]::ReadAllText($iniPath)
    $orig = $text

    foreach ($ext in 'pdo_pgsql', 'pgsql') {
        if ($text -match "(?m)^\s*extension\s*=\s*$ext\s*$") {
            Ok "$ext already enabled"
        } elseif ($text -match "(?m)^\s*;\s*extension\s*=\s*$ext\s*$") {
            $text = [regex]::Replace($text, "(?m)^\s*;\s*extension\s*=\s*$ext\s*$", "extension=$ext")
            Ok "Enabled $ext"
        } else {
            $text += "`r`nextension=$ext`r`n"
            Ok "Added $ext"
        }
    }

    # Windows needs extension_dir so PHP can find the DLLs
    if ($text -notmatch '(?m)^\s*extension_dir\s*=') {
        $rx = [regex]'(?m)^\s*;\s*extension_dir\s*=\s*"ext"'
        if ($rx.IsMatch($text)) {
            $text = $rx.Replace($text, 'extension_dir = "ext"', 1)
            Ok 'Enabled extension_dir = "ext"'
        } else {
            $text += "`r`nextension_dir = `"ext`"`r`n"
            Ok 'Added extension_dir = "ext"'
        }
    }

    if ($text -ne $orig) {
        Copy-Item $iniPath "$iniPath.bak" -Force
        [System.IO.File]::WriteAllText($iniPath, $text, $utf8)
        Ok "Saved php.ini (backup: php.ini.bak)"
    }
} catch {
    Fail "Could not edit $iniPath ($($_.Exception.Message)). Try running PowerShell as Administrator."
}

# --- 4. Check .env ----------------------------------------------
# The real .env (shared dev database) is sent to you privately by the team
# lead. It is never committed. This script never overwrites an existing .env.
if (Test-Path '.env') {
    Ok ".env found (left untouched)"
    $envText = Get-Content '.env' -Raw
    if ($envText -match 'your-database-password|your-pooler-host|your-project-ref') {
        Warn ".env still has placeholder values. Ask the team lead for the real .env file."
        $needsEnv = $true
    }
} else {
    Warn "No .env file found in this folder."
    Warn "Ask the team lead for the .env file and drop it here, next to setup.bat."
    Warn "(Or copy .env.example to .env and fill in the database values yourself.)"
    $needsEnv = $true
}

# --- 5. Verify --------------------------------------------------
$mods = (& php -m) -join "`n"
if ($mods -match '(?mi)^pdo_pgsql$') { Ok "pdo_pgsql is loaded" }
else { Fail "pdo_pgsql still not loaded. Check that php_pdo_pgsql.dll exists in $phpDir\ext" }

Write-Host "`nDone." -ForegroundColor Green
if ($needsEnv) { Write-Host "Next: put the .env file from the team lead in this folder, then start the app." }
Write-Host "Start the app with:  php -S localhost:8000"
Write-Host "Then open:           http://localhost:8000/public/`n"
