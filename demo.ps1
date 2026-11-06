#Requires -Version 5.1
<#
.SYNOPSIS
    CSIT314 T04: Seven - One-click local demo runner.

.DESCRIPTION
    Automated pre-demo setup for the Interior Designer Matching Platform:
      1. Verifies project structure, PHP, PHP extensions, and PostgreSQL.
      2. Stops any stale PHP server on the target port.
      3. Reseeds the database (optionally with 100+ synthetic records).
      4. Starts the PHP built-in server in a new window.
      5. Prints local + network URLs, login credentials, and demo flow.

.PARAMETER FullData
    Also generate 100+ synthetic records for the Reports/analytics demo.

.PARAMETER SkipReset
    Skip database reseed (keep existing data). Useful for mid-demo restarts.

.PARAMETER NoBrowser
    Do not auto-open the browser at the end.

.PARAMETER Port
    Port for the PHP development server. Default: 8000.

.PARAMETER PhpPath
    Explicit path to php.exe. Use if PHP is not on PATH and is not in the
    default XAMPP location (C:\xampp\php\php.exe).

.EXAMPLE
    .\demo.ps1
    Standard demo: reseed, start server, open browser.

.EXAMPLE
    .\demo.ps1 -FullData
    Same, but populate 100+ synthetic records for a fuller Reports demo.

.EXAMPLE
    .\demo.ps1 -Port 8001 -NoBrowser
    Use port 8001; do not open browser.

.EXAMPLE
    .\demo.ps1 -PhpPath "D:\tools\php\php.exe"
    Use a custom PHP installation.

.NOTES
    Requires PHP 8.1+ with pdo_pgsql, pgsql, openssl, mbstring extensions,
    and a running PostgreSQL server with database 'csit314_seven'.
#>

[CmdletBinding()]
param(
    [switch]$FullData,
    [switch]$SkipReset,
    [switch]$NoBrowser,
    [int]$Port = 8000,
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'

# ============================================================================
# COLOR / OUTPUT HELPERS
# ============================================================================
function Write-Banner {
    param($text)
    $line = '=' * 60
    Write-Host ""
    Write-Host $line -ForegroundColor Cyan
    Write-Host " $text" -ForegroundColor Cyan
    Write-Host $line -ForegroundColor Cyan
}

function Write-Step { param($text) Write-Host ""; Write-Host "[>] $text" -ForegroundColor Yellow }
function Write-Ok   { param($text) Write-Host "  [OK]   $text" -ForegroundColor Green }
function Write-Warn { param($text) Write-Host "  [WARN] $text" -ForegroundColor Yellow }
function Write-Fail { param($text) Write-Host "  [FAIL] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "  [INFO] $text" -ForegroundColor Gray }

# ============================================================================
# BANNER
# ============================================================================
Clear-Host
Write-Banner "CSIT314 T04: Seven - Demo Runner"
Write-Info "Project root: $PWD"

# ============================================================================
# STEP 1 - VERIFY PROJECT STRUCTURE
# ============================================================================
Write-Step "Verifying project structure"

$required = @(
    'router.php',
    'db\schema.sql',
    'db\seed.php',
    'src\bce\controllers.php',
    'src\bce\entities.php',
    'src\bce\boundary.php',
    'public\index.html',
    'public\js\app.js',
    'tests\AllTest.php'
)

$missing = @()
foreach ($f in $required) {
    if (-not (Test-Path $f)) { $missing += $f }
}

if ($missing.Count -gt 0) {
    Write-Fail "Missing required files:"
    $missing | ForEach-Object { Write-Host "         - $_" -ForegroundColor Red }
    Write-Info "Run this script from the repository root (folder containing router.php)."
    exit 1
}
Write-Ok "All $($required.Count) required files present"

# ============================================================================
# STEP 2 - VERIFY PHP
# ============================================================================
Write-Step "Verifying PHP"

$phpCmd = $null

# 2a. Try -PhpPath first if provided
if ($PhpPath) {
    if (Test-Path $PhpPath) {
        $phpCmd = $PhpPath
        Write-Ok "Using explicit -PhpPath: $PhpPath"
    } else {
        Write-Fail "-PhpPath '$PhpPath' does not exist"
        exit 1
    }
}

# 2b. Try php on PATH
if (-not $phpCmd) {
    try {
        $phpVersion = & php -v 2>&1
        if ($LASTEXITCODE -eq 0) {
            $phpCmd = 'php'
            Write-Ok "PHP found on PATH: $($phpVersion | Select-Object -First 1)"
        }
    } catch {
        # fall through
    }
}

# 2c. Try common XAMPP default
if (-not $phpCmd) {
    $xamppPhp = 'C:\xampp\php\php.exe'
    if (Test-Path $xamppPhp) {
        $phpCmd = $xamppPhp
        Write-Warn "PHP not on PATH - using default XAMPP path: $xamppPhp"
        Write-Info "To fix permanently: add C:\xampp\php to User PATH via sysdm.cpl"
    }
}

# 2d. Nothing found
if (-not $phpCmd) {
    Write-Fail "PHP not found."
    Write-Info "Options:"
    Write-Info "  1. Install XAMPP (default location: C:\xampp\php)"
    Write-Info "  2. Add your PHP folder to the system PATH"
    Write-Info "  3. Pass an explicit path: .\demo.ps1 -PhpPath C:\path\to\php.exe"
    exit 1
}

# Verify required PHP extensions
# Note: $extOutput must be a single string. `-match` on an array returns
# the array of non-matching elements, which is always truthy for multi-line
# output - that would produce false positives on every extension check.
$extOutput = (& $phpCmd -m 2>&1) -join "`n"
$needed = @('pdo_pgsql', 'pgsql', 'openssl', 'mbstring')
$extMissing = @()
foreach ($e in $needed) {
    if ($extOutput -notmatch "(?im)^\s*$e\s*$") {
        $extMissing += $e
    }
}
if ($extMissing.Count -gt 0) {
    Write-Fail "Missing PHP extensions: $($extMissing -join ', ')"
    Write-Info "Enable them in php.ini by uncommenting the corresponding ';extension=...' lines."
    Write-Info "Typical location: C:\xampp\php\php.ini"
    exit 1
}
Write-Ok "Required PHP extensions loaded: $($needed -join ', ')"

# ============================================================================
# STEP 3 - VERIFY POSTGRESQL
# ============================================================================
Write-Step "Verifying PostgreSQL connection"

try {
    $null = & psql -U postgres -d csit314_seven -c "SELECT 1;" 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "psql returned exit code $LASTEXITCODE"
    }
    Write-Ok "Connected to database 'csit314_seven'"
} catch {
    Write-Fail "Cannot connect to PostgreSQL database 'csit314_seven'"
    Write-Info "1. Ensure the PostgreSQL service is running (Services - postgresql-x64-15)"
    Write-Info "2. Ensure the database exists:"
    Write-Info "     psql -U postgres -c 'CREATE DATABASE csit314_seven;'"
    Write-Info "3. Check credentials in .env (see .env.example)"
    exit 1
}

# ============================================================================
# STEP 4 - STOP ANY EXISTING PHP SERVER ON THE PORT
# ============================================================================
Write-Step "Checking for existing server on port $Port"

$existing = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($existing) {
    $ownerPid = $existing[0].OwningProcess
    $proc = Get-Process -Id $ownerPid -ErrorAction SilentlyContinue
    if ($proc -and $proc.ProcessName -eq 'php') {
        Write-Warn "Existing PHP server on port $Port (PID $ownerPid) - stopping"
        Stop-Process -Id $ownerPid -Force
        Start-Sleep -Milliseconds 500
        Write-Ok "Stopped old server"
    } else {
        $procName = if ($proc) { $proc.ProcessName } else { 'unknown' }
        Write-Fail "Port $Port is in use by process '$procName' (PID $ownerPid)"
        Write-Info "Stop it manually, or use: .\demo.ps1 -Port 8001"
        exit 1
    }
} else {
    Write-Ok "Port $Port is free"
}

# ============================================================================
# STEP 5 - RESET DATABASE
# ============================================================================
if ($SkipReset) {
    Write-Step "Skipping database reset via -SkipReset"
    Write-Warn "Using existing data - demo may show stale state"
} else {
    Write-Step "Resetting database"

    & $phpCmd db/seed.php
    if ($LASTEXITCODE -ne 0) {
        Write-Fail "Seed script failed"
        exit 1
    }
    Write-Ok "Base seed complete: 5 users, 2 companies, 4 projects"

    if ($FullData) {
        Write-Step "Generating 100+ synthetic records"
        & $phpCmd db/generate_test_data.php
        if ($LASTEXITCODE -ne 0) {
            Write-Warn "Synthetic data generation failed - continuing with base data"
        } else {
            Write-Ok "Synthetic data generated"
        }
    } else {
        Write-Info "Skipped synthetic data. Use -FullData to generate 100+ records."
    }
}

# ============================================================================
# STEP 6 - START SERVER IN A NEW WINDOW
# ============================================================================
Write-Step "Starting PHP dev server on port $Port in new window"

$serverCmd = "Set-Location '$PWD'; Write-Host 'PHP Dev Server - close this window to stop' -ForegroundColor Cyan; & '$phpCmd' -S 0.0.0.0:$Port router.php"
Start-Process powershell -ArgumentList @('-NoExit', '-Command', $serverCmd) | Out-Null
Start-Sleep -Seconds 2

# Verify server responds
$serverUp = $false
for ($i = 0; $i -lt 20; $i++) {
    try {
        $null = Invoke-WebRequest -Uri "http://localhost:$Port/" -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        $serverUp = $true
        break
    } catch {
        Start-Sleep -Milliseconds 300
    }
}

if (-not $serverUp) {
    Write-Fail "Server did not respond on http://localhost:$Port/"
    Write-Info "Check the server window for errors."
    exit 1
}
Write-Ok "Server responding on port $Port"

# ============================================================================
# STEP 7 - DETECT LOCAL IP
# ============================================================================
Write-Step "Detecting network addresses"

$localUrl = "http://localhost:$Port"
$networkIp = $null

try {
    $candidates = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            $_.IPAddress -notlike '127.*' -and
            $_.IPAddress -notlike '169.254.*' -and
            $_.PrefixOrigin -ne 'WellKnown'
        } |
        Sort-Object -Property InterfaceMetric

    if ($candidates) { $networkIp = $candidates[0].IPAddress }
} catch { }

# ============================================================================
# STEP 8 - DISPLAY DEMO INFO
# ============================================================================
Write-Banner "DEMO READY"

Write-Host ""
Write-Host "  URLS" -ForegroundColor White
Write-Host "     Local:    $localUrl" -ForegroundColor Green
if ($networkIp) {
    Write-Host "     Network:  http://${networkIp}:$Port" -ForegroundColor Green
    Write-Host "               (open from phone or tablet on same Wi-Fi)" -ForegroundColor DarkGray
} else {
    Write-Host "     Network:  (no active network adapter detected)" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "  LOGIN CREDENTIALS - Password: Password123!" -ForegroundColor White

$creds = @(
    @{ Email = 'admin@example.test';     Role = 'User Admin';        Demo = 'User accounts, profiles, RBAC' },
    @{ Email = 'designer@example.test';  Role = 'Interior Designer'; Demo = 'Companies, projects, analytics' },
    @{ Email = 'customer@example.test';  Role = 'Customer';          Demo = 'Browse, favourites, search' },
    @{ Email = 'manager@example.test';   Role = 'Platform Manager';  Demo = 'Categories, reports' },
    @{ Email = 'suspended@example.test'; Role = '[SUSPENDED]';       Demo = 'Shows 403 error (US #6, #11)' }
)

foreach ($c in $creds) {
    Write-Host ("     {0,-30} {1,-20} {2}" -f $c.Email, $c.Role, $c.Demo) -ForegroundColor Gray
}

Write-Host ""
Write-Host "  SUGGESTED DEMO FLOW" -ForegroundColor White
Write-Host "     1. Login as admin        -> RBAC: only admin modules appear in nav" -ForegroundColor Gray
Write-Host "     2. Login as suspended    -> 403 error toast" -ForegroundColor Gray
Write-Host "     3. Login as designer     -> create company, project; view analytics" -ForegroundColor Gray
Write-Host "     4. Login as customer     -> browse gallery, save to favourites" -ForegroundColor Gray
Write-Host "     5. Login as manager      -> create category; view daily/weekly/monthly reports" -ForegroundColor Gray
Write-Host "     6. Delete company with projects -> 400 error (ON DELETE RESTRICT)" -ForegroundColor Gray

Write-Host ""
Write-Host "  OPTIONAL TECHNICAL PROOF - run in another terminal:" -ForegroundColor White
Write-Host "       php vendor\bin\phpunit --testdox" -ForegroundColor DarkCyan
Write-Host "     -> 20 tests, 86 assertions, all green" -ForegroundColor DarkGray

# ============================================================================
# STEP 9 - OPEN BROWSER
# ============================================================================
if (-not $NoBrowser) {
    Write-Step "Opening browser"
    Start-Process $localUrl
    Write-Ok "Browser launched"
}

# ============================================================================
# STEP 10 - READY PROMPT
# ============================================================================
Write-Banner "READY FOR DEMO"

Write-Host ""
Write-Host "  IMPORTANT" -ForegroundColor Yellow
Write-Host "     - Do NOT close the PHP server window" -ForegroundColor Yellow
Write-Host "     - Press Ctrl+C in the server window to stop" -ForegroundColor Yellow
Write-Host "     - Reseed anytime with: php db/seed.php" -ForegroundColor Yellow

Write-Host ""
Write-Host "  TO STOP THE DEMO:" -ForegroundColor White
Write-Host "     1. Close the PHP server window" -ForegroundColor Gray
Write-Host "     OR run: Get-Process php | Stop-Process -Force" -ForegroundColor Gray

Write-Host ""

$runTests = Read-Host "Run integration tests now? (y/N)"
if ($runTests -eq 'y' -or $runTests -eq 'Y') {
    Write-Step "Running PHPUnit integration tests"
    & $phpCmd vendor\bin\phpunit --testdox
}

Write-Host ""
Write-Host "  Demo setup complete. Good luck!" -ForegroundColor Green
Write-Host ""