# Connects the deployed Vercel app to your Neon Postgres.
# Run this yourself so the database password never has to be pasted into a chat.
#
#   powershell -ExecutionPolicy Bypass -File .\connect-neon.ps1
#
# It will prompt for your Neon password (input is hidden), then set the
# environment variables on the Vercel project and run the migrations.

$ErrorActionPreference = "Stop"
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

# Your Vercel token is NOT stored in this file. Set it first, in this terminal only:
#   $env:VERCEL_TOKEN = "your-vercel-token"
$vercelToken = $env:VERCEL_TOKEN
if ([string]::IsNullOrWhiteSpace($vercelToken)) {
    Write-Host ""
    Write-Host "VERCEL_TOKEN is not set." -ForegroundColor Red
    Write-Host "Create one at vercel.com -> Account Settings -> Tokens, then run:" -ForegroundColor Gray
    Write-Host '  $env:VERCEL_TOKEN = "your-token"' -ForegroundColor Yellow
    Write-Host "  powershell -ExecutionPolicy Bypass -File .\connect-neon.ps1" -ForegroundColor Yellow
    exit 1
}

$project = "prj_FCo7CPA6z5fKciEXCUREL8xu25uG"
$appUrl  = "https://erp-eight-orcin.vercel.app"

# The release token is a secret too, so keep it out of the repository as well.
$releaseToken = $env:RELEASE_TOKEN
if ([string]::IsNullOrWhiteSpace($releaseToken)) {
    $tokenFile = Join-Path $env:TEMP "opencode\release_token.txt"
    if (Test-Path $tokenFile) { $releaseToken = (Get-Content $tokenFile -Raw).Trim() }
}

Write-Host ""
Write-Host "=== Neon database connection ===" -ForegroundColor Cyan
Write-Host "Neon console: Dashboard -> Connection Details"
Write-Host "Password is hidden while you type." -ForegroundColor DarkGray
Write-Host ""

$secure = Read-Host "Paste your Neon password" -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

if ([string]::IsNullOrWhiteSpace($password) -or $password -eq "******") {
    Write-Host ""
    Write-Host "That password looks empty or still masked. Use the real one." -ForegroundColor Red
    exit 1
}

# URL-encode the password so special characters cannot break the connection string
$encoded = [Uri]::EscapeDataString($password)
$dbUrl = "postgresql://neondb_owner:$encoded@ep-plain-star-b4nhnw8u-pooler.c-6.us-east-2.aws.neon.tech/neondb?sslmode=require"

Write-Host ""
Write-Host "Setting env vars on Vercel..." -ForegroundColor Cyan
$h = @{
    "Authorization" = "Bearer $vercelToken"
    "Accept"        = "application/json"
    "Content-Type"  = "application/json"
}

function Set-VercelEnv($key, $value) {
    $existing = Invoke-RestMethod -Uri "https://api.vercel.com/v9/projects/$project/env" -Headers $h -TimeoutSec 30
    $old = $existing.envs | Where-Object { $_.key -eq $key -and $_.target -contains 'production' }
    foreach ($o in $old) {
        $null = Invoke-RestMethod -Uri "https://api.vercel.com/v9/projects/$project/env/$($o.id)" -Headers $h -Method Delete -TimeoutSec 30
    }
    $body = @{ key = $key; value = $value; type = "encrypted"; target = @("production") } | ConvertTo-Json -Depth 4
    $null = Invoke-RestMethod -Uri "https://api.vercel.com/v10/projects/$project/env" -Headers $h -Method Post -Body $body -TimeoutSec 30
    Write-Host "  set $key" -ForegroundColor Green
}

Set-VercelEnv "DB_CONNECTION" "pgsql"
Set-VercelEnv "DB_URL" $dbUrl
Set-VercelEnv "SESSION_DRIVER" "database"
Set-VercelEnv "CACHE_STORE" "database"

Write-Host ""
Write-Host "Redeploying so the new env vars take effect..." -ForegroundColor Cyan
Push-Location $PSScriptRoot
$env:VERCEL_TELEMETRY_DISABLED = "1"
npx --yes vercel@latest deploy --prod --yes --token $vercelToken | Out-Null
Pop-Location
Write-Host "  done" -ForegroundColor Green

Write-Host ""
Write-Host "Running migrations against Neon..." -ForegroundColor Cyan
try {
    $r = Invoke-WebRequest -Uri "$appUrl/internal/migrate" -Method Post -Headers @{ "X-Release-Token" = $releaseToken } -UseBasicParsing -TimeoutSec 300
    Write-Host $r.Content -ForegroundColor DarkGray
}
catch {
    Write-Host "  migrate call failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "  The app is still deployed; the database is configured but empty." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Done. Log in at $appUrl/login" -ForegroundColor Cyan
Write-Host "  email:    admin@company.com" -ForegroundColor Gray
Write-Host "  password: password" -ForegroundColor Gray
Write-Host ""
