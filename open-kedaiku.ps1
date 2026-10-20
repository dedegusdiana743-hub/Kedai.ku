param(
    [string]$Hostname = 'kedaiku.local',
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$url = "http://$Hostname`:$Port/toko/kedai-ku"

$listening = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if (-not $listening) {
    Write-Host "Server belum jalan, menyalakan..."
    & (Join-Path $projectRoot 'scripts\start-local.ps1') -Port $Port | Out-Null
    Start-Sleep -Seconds 2
}

try {
    Invoke-WebRequest "http://localhost:$Port/health" -UseBasicParsing -TimeoutSec 5 | Out-Null
} catch {
    Write-Warning 'Health check gagal. Pastikan MySQL XAMPP berjalan.'
}

Start-Process $url
Write-Host "Dibuka: $url"