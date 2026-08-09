$ProjectPath  = "C:\wamp64\www\4th_year_project"
$DockerExe    = "C:\Program Files\Docker\Docker\Docker Desktop.exe"

function Info ($msg) { Write-Host "  $msg" -ForegroundColor Cyan }
function OK   ($msg) { Write-Host "  OK: $msg" -ForegroundColor Green }
function Wait ($msg) { Write-Host "  >> $msg" -ForegroundColor Yellow }
function Step ($msg) { Write-Host "`n--- $msg ---" -ForegroundColor White }

Step "Docker Desktop"
if (Get-Process "Docker Desktop" -ErrorAction SilentlyContinue) { OK "Docker already running" }
else { Wait "Starting Docker Desktop, waiting 35s..."; Start-Process $DockerExe; Start-Sleep -Seconds 35 }

Wait "Waiting for Docker daemon..."
$tries = 0
do { Start-Sleep -Seconds 5; $out = docker info 2>&1; $tries++ } until ($LASTEXITCODE -eq 0 -or $tries -gt 18)
OK "Docker daemon ready"

Step "Starting all containers"
Set-Location $ProjectPath
docker compose up -d mysql redis nginx app1 app2 app3

Step "Waiting for MySQL to be healthy"
$tries = 0
do {
    Start-Sleep -Seconds 5
    $health = docker inspect --format="{{.State.Health.Status}}" syride_mysql 2>&1
    $tries++
    Info "MySQL: $health"
} until ($health -eq "healthy" -or $tries -gt 24)
OK "MySQL is healthy"

Wait "Letting app containers settle (20s)..."
Start-Sleep -Seconds 20

Step "Clearing Laravel cache"
docker exec syride_app1 php artisan config:clear
docker exec syride_app1 php artisan cache:clear
OK "Cache cleared"

Step "Redis check"
$ping = docker exec syride_redis redis-cli ping
if ($ping -eq "PONG") { OK "Redis responding" } else { Write-Host "  Redis not responding" -ForegroundColor Red }

Step "Container status"
docker ps --format "table {{.Names}}`t{{.Status}}`t{{.Ports}}" --filter "name=syride"

Write-Host "`n  SyRide stack is READY" -ForegroundColor Green
Write-Host "  Postman base_url  -> http://localhost:8080" -ForegroundColor Green
Write-Host "  Login: primary@admin.com / admin`n" -ForegroundColor Green
