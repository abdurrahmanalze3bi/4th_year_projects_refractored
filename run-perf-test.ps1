#Requires -Version 5.1
<#
.SYNOPSIS
    Automated 3-stage SyRide performance comparison.
    A: LB + Cache + Workers             (no indexes, no replica)
    B: LB + Cache + Workers + Indexes
    C: LB + Cache + Workers + Indexes + Replica
.USAGE
    cd C:\wamp64\www\4th_year_project
    .\run-perf-test.ps1
#>

Set-StrictMode -Version Latest
# 'Continue' not 'Stop': MySQL stderr password warnings are not fatal errors.
# We use explicit 'throw' where we actually want to abort.
$ErrorActionPreference = 'Continue'

# -- Settings ------------------------------------------------------------------
$APPS      = @("syride_app1","syride_app2","syride_app3","syride_app4","syride_app5")
$DB        = "syride_mysql"
$DB_NAME   = "4th_year_project_db"
$DB_CFG    = "config/database.php"
$DB_BKP    = "config/database.php.perf-backup"
$K6_SCRIPT = "syride-perf-test.js"
$OUT       = "perf-results"

# -- Console helpers -----------------------------------------------------------
function Write-Step   { param([string]$m) Write-Host "`n>> $m" -ForegroundColor Cyan    }
function Write-OK     { param([string]$m) Write-Host "   [OK] $m" -ForegroundColor Green }
function Write-Banner { param([string]$m, [string]$c) Write-Host "`n===== $m =====" -ForegroundColor $c }

# Runs SQL; returns only stdout strings, drops MySQL password warnings.
function Invoke-Mysql {
    param([string]$sql)
    docker exec $DB mysql -uroot -psecret $DB_NAME -e $sql 2>&1 |
        Where-Object { ($_ -is [string]) -and ($_ -notmatch 'Warning|insecure') }
}

# Like Invoke-Mysql but uses --skip-column-names -B for clean one-value-per-line output.
function Invoke-MysqlBatch {
    param([string]$sql)
    docker exec $DB mysql -uroot -psecret --skip-column-names -B $DB_NAME -e $sql 2>&1 |
        Where-Object { ($_ -is [string]) -and ($_ -notmatch 'Warning|insecure') -and ($_.Trim() -ne '') }
}

# -- Prerequisites -------------------------------------------------------------
function Assert-Prerequisites {
    Write-Step "Checking prerequisites..."
    if (-not (Get-Command k6 -ErrorAction SilentlyContinue)) {
        throw "k6 not found. Install: https://k6.io/docs/get-started/installation/"
    }
    if (-not (Test-Path $K6_SCRIPT)) {
        throw "$K6_SCRIPT not found in current directory."
    }
    if (-not (Test-Path $DB_CFG)) {
        throw "config/database.php not found. Run from the project root."
    }
    Write-OK "k6, Docker, config all present"
}

# -- JWT token -----------------------------------------------------------------
function Get-Token {
    Write-Step "Generating fresh JWT token..."
    $raw = docker exec syride_app1 php artisan loadtest:tokens --count=1 2>&1 | Out-String
    if ($raw -match "PASSENGER_TOKENS\s*=\s*\[\s*'([^']+)'") {
        $t = $Matches[1]
        Write-OK "Token obtained (expires ~10h)"
        return $t
    }
    throw "Could not parse JWT token.`nArtisan output:`n$raw"
}

# -- Index management ----------------------------------------------------------
#
# FIX vs previous version: the old script hard-coded index names like
# 'idx_rides_status_departure_seats' but the migration created them as
# 'rides_status_departure_seats_index'. Stage A never actually dropped anything.
# This version queries INFORMATION_SCHEMA so it drops whatever names exist.
#
# Safety filters:
#   INDEX_NAME != 'PRIMARY'          -- never drop the PK
#   NON_UNIQUE  = 1                  -- skip UNIQUE constraints (schema requirements)
#   INDEX_NAME NOT LIKE '%_foreign'  -- skip FK backing indexes (Laravel names them *_foreign)
#
function Set-Indexes {
    param([bool]$on)
    if ($on) {
        Write-Step "Creating 4 performance indexes (may take 30-60s on 500K rows)..."
        Invoke-Mysql "CREATE INDEX IF NOT EXISTS idx_perf_rides_status_dept    ON rides    (status, departure_time, available_seats)" | Out-Null
        Invoke-Mysql "CREATE INDEX IF NOT EXISTS idx_perf_rides_driver_status  ON rides    (driver_id, status)"                       | Out-Null
        Invoke-Mysql "CREATE INDEX IF NOT EXISTS idx_perf_book_user_status     ON bookings (user_id, status)"                         | Out-Null
        Invoke-Mysql "CREATE INDEX IF NOT EXISTS idx_perf_book_ride_status     ON bookings (ride_id, status)"                         | Out-Null
        Write-OK "Done"
    } else {
        Write-Step "Dropping ALL non-PK non-FK indexes on rides and bookings..."
        $sql = @"
SELECT CONCAT(TABLE_NAME, '|', INDEX_NAME)
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = '$DB_NAME'
  AND TABLE_NAME IN ('rides','bookings')
  AND INDEX_NAME   != 'PRIMARY'
  AND NON_UNIQUE    = 1
  AND INDEX_NAME NOT LIKE '%_foreign'
GROUP BY TABLE_NAME, INDEX_NAME
"@
        $lines = Invoke-MysqlBatch $sql
        foreach ($line in $lines) {
            $line = $line.Trim()
            if ($line -match '^(\w+)\|(\w+)$') {
                $tbl = $Matches[1]
                $idx = $Matches[2]
                Invoke-Mysql "DROP INDEX IF EXISTS $idx ON $tbl" | Out-Null
                Write-Host "    Dropped $tbl.$idx" -ForegroundColor DarkGray
            }
        }
        Write-OK "Done"
    }
}

# -- EXPLAIN output ------------------------------------------------------------
# type:ALL  = full scan (what we expect in Stage A with 500K rows)
# type:ref  = index equality lookup (what we expect in B and C)
# type:range = index range scan
function Show-Explain {
    param([string]$label)
    Write-Host "`n  EXPLAIN rides query ($label):" -ForegroundColor DarkYellow
    $sql = "EXPLAIN SELECT id, pickup_address, destination_address, departure_time, available_seats FROM rides WHERE status = 'active' AND available_seats > 0 ORDER BY departure_time DESC LIMIT 20"
    Invoke-Mysql $sql | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkYellow }
    Write-Host "  ^ type:ALL = full table scan   type:ref/range = index used" -ForegroundColor DarkYellow
}

# -- Replica toggle ------------------------------------------------------------
function Set-Replica {
    param([bool]$on)
    if (-not (Test-Path $DB_BKP)) { throw "Config backup missing: $DB_BKP" }
    if ($on) {
        Write-Step "Replica ON -- reads go to syride_mysql_replica"
        Copy-Item $DB_BKP $DB_CFG -Force
    } else {
        Write-Step "Replica OFF -- reads go to primary only"
        (Get-Content $DB_BKP) -replace 'syride_mysql_replica','syride_mysql' |
            Set-Content $DB_CFG
    }
    Write-OK "database.php updated"
}

# -- Reload all app containers -------------------------------------------------
function Reload-All {
    Write-Step "config:cache + octane:reload on all containers..."
    foreach ($app in $APPS) {
        docker exec $app php artisan config:cache  2>&1 | Out-Null
        docker exec $app php artisan octane:reload 2>&1 | Out-Null
        Write-Host "   [OK] $app" -ForegroundColor Green
    }
}

# -- Run k6 for one stage ------------------------------------------------------
function Invoke-Stage {
    param([string]$name, [string]$token)
    Write-Step "Running k6 stage: $name"
    $jsonOut = Join-Path $OUT "$name.json"
    $txtOut  = Join-Path $OUT "$name.txt"
    $env:K6_NO_USAGE_REPORT = "true"
    # FIX vs previous version: pipe k6 output through Out-Host so it goes to
    # the console directly (information stream) and does NOT get captured into
    # the return value. Without this, $fA/$fB/$fC would be arrays of k6 output
    # lines plus the path, breaking ConvertFrom-Json in Print-Results.
    k6 run --env "TOKEN=$token" --summary-export $jsonOut $K6_SCRIPT 2>&1 |
        Tee-Object -FilePath $txtOut |
        Out-Host
    Write-OK "Results -> $jsonOut"
    $jsonOut     # sole return value
}

# -- Parse one k6 JSON metric --------------------------------------------------
function Get-M {
    param([object]$d, [string]$key, [string]$stat)
    try {
        $prop = $d.metrics.PSObject.Properties[$key]
        if ($null -eq $prop) { return "N/A" }
        $vp = $prop.Value.values.PSObject.Properties[$stat]
        if ($null -eq $vp)   { return "N/A" }
        $n = [double]$vp.Value
        if ($key -match 'failed') { return ("{0:P1}" -f $n) }
        return [math]::Round($n, 0)
    } catch { return "N/A" }
}

# -- Final comparison table ----------------------------------------------------
function Print-Results {
    param([string[]]$jsonFiles)
    $parsed = $jsonFiles | ForEach-Object { ConvertFrom-Json (Get-Content $_ -Raw) }
    $cols   = @("A: Baseline", "B: +Indexes", "C: +Replica")

    $rows = @(
        @{ label = "Overall p95 (ms)      "; key = "http_req_duration";                    stat = "p(95)" },
        @{ label = "Overall p99 (ms)      "; key = "http_req_duration";                    stat = "p(99)" },
        @{ label = "Overall avg (ms)      "; key = "http_req_duration";                    stat = "avg"   },
        @{ label = "Rides p95 (ms)        "; key = "http_req_duration{name:rides}";        stat = "p(95)" },
        @{ label = "Rides p99 (ms)        "; key = "http_req_duration{name:rides}";        stat = "p(99)" },
        @{ label = "Transactions p95 (ms) "; key = "http_req_duration{name:transactions}"; stat = "p(95)" },
        @{ label = "Throughput (req/s)    "; key = "http_reqs";                            stat = "rate"  },
        @{ label = "Error rate            "; key = "http_req_failed";                      stat = "rate"  }
    )

    $sep = "=" * 72
    Write-Host "`n$sep" -ForegroundColor Magenta
    Write-Host "  SYRIDE PERFORMANCE RESULTS  --  500K rides / 1M bookings in DB" -ForegroundColor Magenta
    Write-Host $sep -ForegroundColor Magenta
    Write-Host ("  {0,-26}  {1,14}  {2,14}  {3,14}" -f "Metric", $cols[0], $cols[1], $cols[2]) -ForegroundColor White
    Write-Host ("  " + "-" * 68) -ForegroundColor DarkGray

    foreach ($r in $rows) {
        $v = @(
            (Get-M $parsed[0] $r.key $r.stat),
            (Get-M $parsed[1] $r.key $r.stat),
            (Get-M $parsed[2] $r.key $r.stat)
        )
        Write-Host ("  {0,-26}  {1,14}  {2,14}  {3,14}" -f $r.label, $v[0], $v[1], $v[2])
    }

    Write-Host ("  " + "-" * 68) -ForegroundColor DarkGray
    Write-Host "`n  Logs: $OUT\*.txt     JSON: $OUT\*.json" -ForegroundColor DarkGray
    Write-Host "$sep`n" -ForegroundColor Magenta
}

# =============================================================================
# MAIN
# =============================================================================

Assert-Prerequisites
New-Item -ItemType Directory -Force -Path $OUT | Out-Null

Copy-Item $DB_CFG $DB_BKP -Force
Write-OK "Config backed up to $DB_BKP"

$token = Get-Token

try {
    # ---- Stage A: no indexes, no replica ------------------------------------
    Write-Banner "STAGE A  --  LB + Cache + Workers  (no indexes, no replica)" "Yellow"
    Set-Indexes -on $false
    Set-Replica -on $false
    Reload-All
    Show-Explain "Stage A -- expect type:ALL, rows:500000+"
    $fA = Invoke-Stage "A_baseline" $token

    # ---- Stage B: indexes on, replica off -----------------------------------
    Write-Banner "STAGE B  --  + Indexes  (replica still off)" "Blue"
    Set-Indexes -on $true
    Reload-All
    Show-Explain "Stage B -- expect type:ref, rows:much smaller"
    $fB = Invoke-Stage "B_indexes" $token

    # ---- Stage C: indexes + replica -----------------------------------------
    Write-Banner "STAGE C  --  + Indexes + Replica  (full stack)" "Green"
    Set-Replica -on $true
    Reload-All
    $fC = Invoke-Stage "C_full" $token

    Print-Results @($fA, $fB, $fC)

} finally {
    Write-Step "Restoring config and indexes..."
    if (Test-Path $DB_BKP) {
        Copy-Item $DB_BKP $DB_CFG -Force
        Remove-Item $DB_BKP -Force
    }
    # Re-add performance indexes -- Stage A dropped all of them.
    Set-Indexes -on $true
    Reload-All
    Write-OK "Done. Config and indexes restored."
}
