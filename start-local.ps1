param(
    [string]$XamppRoot = 'D:\xampp',
    [int]$Port = 8080,
    [switch]$SetPublicUrl,
    [switch]$AllowFirewall
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $XamppRoot 'php\php.exe'
if (-not (Test-Path $php)) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
}
if (-not $php -or -not (Test-Path $php)) { throw 'PHP tidak ditemukan. Pass -XamppRoot atau install PHP.' }

$lanConfig = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
    Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq 'Up' } |
    Select-Object -First 1
$lanIp = if ($lanConfig) { $lanConfig.IPv4Address.IPAddress } else { '' }

$existing = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($existing -and $existing.LocalAddress -notin @('0.0.0.0', '::')) {
    Write-Host "Menghentikan server lama yang hanya mendengarkan di $($existing.LocalAddress)."
    Stop-Process -Id $existing.OwningProcess -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    $existing = $null
}
if ($existing) {
    Write-Host "Port $Port sudah digunakan oleh PID $($existing.OwningProcess)."
} else {
    Start-Process -FilePath $php -ArgumentList @('-S', "0.0.0.0:$Port", '-t', 'public', 'public/index.php') -WorkingDirectory $projectRoot -WindowStyle Hidden
    Start-Sleep -Seconds 2
}

if ($SetPublicUrl -and $lanIp) {
    $envFile = Join-Path $projectRoot '.env'
    if (Test-Path $envFile) {
        $content = Get-Content $envFile -Raw
        $current = [regex]::Match($content, '(?m)^APP_URL=(.*)$').Groups[1].Value.Trim()
        if ($current -eq '' -or $current -match 'localhost|127\.0\.0\.1') {
            $content = [regex]::Replace($content, '(?m)^APP_URL=.*$', "APP_URL=http://$lanIp`:$Port")
            Set-Content -Path $envFile -Value $content -Encoding UTF8
            Write-Host "APP_URL diubah ke http://$lanIp`:$Port"
        } else {
            Write-Host "APP_URL tidak diubah (tetap $current)."
        }
    }
}

if ($AllowFirewall) {
    $networkProfiles = Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.IPv4Connectivity -ne 'Disconnected' }
    if ($networkProfiles | Where-Object { $_.NetworkCategory -eq 'Public' }) {
        Write-Warning 'Wi-Fi saat ini berstatus Public. Untuk akses HP, jalankan PowerShell sebagai Administrator dan setel profil ke Private atau izinkan port secara manual.'
    }
    try {
        $ruleName = "PesanMenu TCP $Port"
        if (-not (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
            New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -Profile Private -ErrorAction Stop | Out-Null
            Write-Host "Firewall rule dibuat: $ruleName"
        } else {
            Write-Host "Firewall rule sudah ada: $ruleName"
        }
    } catch {
        Write-Warning "Tidak dapat membuat firewall rule. Jalankan PowerShell sebagai Administrator jika handset belum bisa terhubung."
    }
}

Write-Host "PesanMenu berjalan di http://localhost:$Port"
if ($lanIp) { Write-Host "Untuk HP pada Wi-Fi yang sama: http://$lanIp`:$Port" }
Write-Host "Toko: http://localhost:$Port/toko/kedai-ku"
Write-Host "Health: http://localhost:$Port/health"
Write-Host "Login: http://localhost:$Port/penjual/login"