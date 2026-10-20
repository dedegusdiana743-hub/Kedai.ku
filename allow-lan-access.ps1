param(
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Administrator)) {
    Write-Host 'Meminta izin Administrator untuk membuka port firewall...'
    Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Port', $Port)
    exit
}

$ruleName = "PesanMenu TCP $Port (LAN)"
$existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
if ($existing) {
    Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
}

New-NetFirewallRule `
    -DisplayName $ruleName `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort $Port `
    -Profile Any `
    -RemoteAddress LocalSubnet `
    -Description 'Allow PesanMenu diakses dari perangkat lain pada Wi-Fi lokal' | Out-Null

Write-Host "Firewall rule dibuat: $ruleName (hanya jaringan lokal)."
Write-Host "Port $Port sekarang bisa diakses dari HP pada Wi-Fi yang sama."

$ip = (Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1).IPv4Address.IPAddress
if ($ip) {
    Write-Host "Link HP : http://$ip`:$Port/toko/kedai-ku"
}