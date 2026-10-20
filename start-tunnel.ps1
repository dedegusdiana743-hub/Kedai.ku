param(
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$cloudflared = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
if (-not $cloudflared) {
    $candidates = @(
        'C:\Program Files (x86)\cloudflared\cloudflared.exe',
        'C:\Program Files\cloudflared\cloudflared.exe',
        "$env:LOCALAPPDATA\Microsoft\WinGet\Packages"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path $candidate -PathType Leaf) { $cloudflared = $candidate; break }
    }
}
if (-not $cloudflared) { throw 'cloudflared belum ditemukan. Jalankan: winget install --id Cloudflare.cloudflared --exact' }

$local = "http://127.0.0.1:$Port"
try {
    $health = Invoke-WebRequest "$local/health" -UseBasicParsing -TimeoutSec 5
    if ($health.StatusCode -ne 200) { throw "Local health check mengembalikan $($health.StatusCode)." }
} catch {
    throw "Server lokal belum aktif. Jalankan scripts\start-local.ps1 lebih dahulu. Detail: $($_.Exception.Message)"
}

$logDirectory = Join-Path (Split-Path -Parent $PSScriptRoot) 'storage\logs'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
$logFile = Join-Path $logDirectory 'cloudflared.log'
$process = Start-Process -FilePath $cloudflared -ArgumentList @('tunnel', '--no-autoupdate', '--protocol', 'http2', '--url', $local) -RedirectStandardOutput $logFile -RedirectStandardError "$logFile.err" -WindowStyle Hidden -PassThru
$urlFile = Join-Path $logDirectory 'cloudflared.url'
$deadline = (Get-Date).AddSeconds(30)
$publicUrl = ''
while ((Get-Date) -lt $deadline -and !$publicUrl) {
    Start-Sleep -Milliseconds 500
    $combined = ((Get-Content $logFile -Raw -ErrorAction SilentlyContinue) + "`n" + (Get-Content "$logFile.err" -Raw -ErrorAction SilentlyContinue))
    $match = [regex]::Match($combined, 'https://[a-z0-9-]+\.trycloudflare\.com')
    if ($match.Success) { $publicUrl = $match.Value }
}
if ($publicUrl) {
    Set-Content -Path $urlFile -Value $publicUrl -Encoding ASCII
    Write-Host "URL publik: $publicUrl"
    Write-Host "URL disimpan di $urlFile"
} else {
    Write-Warning "URL belum ditemukan dalam 30 detik. Periksa $logFile dan $logFile.err."
}
Write-Host "Tunnel dibuat. PID: $($process.Id)"
Write-Host "Link berubah setiap kali tunnel dimulai ulang."
Write-Host "Tekan Ctrl+C tidak menghentikan proses; gunakan: Stop-Process -Id $($process.Id)"