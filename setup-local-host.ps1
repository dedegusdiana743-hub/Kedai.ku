param(
    [string]$Hostname = 'kedaiku.local',
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Administrator)) {
    Write-Host 'Meminta izin Administrator untuk menulis hosts file...'
    Start-Process powershell -Verb RunAs -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Hostname', $Hostname, '-Port', $Port)
    exit
}

$hostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$existing = Get-Content -LiteralPath $hostsPath -ErrorAction SilentlyContinue
$cleaned = $existing | Where-Object { $_ -notmatch [regex]::Escape($Hostname) }
$cleaned += "127.0.0.1`t$Hostname"
Set-Content -LiteralPath $hostsPath -Value $cleaned -Encoding ASCII

$envFile = Join-Path $projectRoot '.env'
if (Test-Path $envFile) {
    $content = Get-Content $envFile -Raw
    $content = [regex]::Replace($content, '(?m)^APP_URL=.*$', "APP_URL=http://$Hostname`:$Port")
    $content = [regex]::Replace($content, '(?m)^TRUSTED_HOSTS=.*$', "TRUSTED_HOSTS=$Hostname")
    $content = [regex]::Replace($content, '(?m)^PUBLIC_HOST=.*$', 'PUBLIC_HOST=')
    $content = [regex]::Replace($content, '(?m)^TRUST_PROXY=.*$', 'TRUST_PROXY=false')
    if ($content -notmatch '(?m)^TRUSTED_HOSTS=') {
        $content += "`nTRUSTED_HOSTS=$Hostname`n"
    }
    Set-Content -LiteralPath $envFile -Value $content -Encoding UTF8
}

Write-Host "Selesai. Host lokal sudah dibuat: $Hostname"
Write-Host "Toko   : http://$Hostname`:$Port/toko/kedai-ku"
Write-Host "Login  : http://$Hostname`:$Port/penjual/login"
Write-Host 'Link ini hanya untuk jaringan lokal (tidak publik).'