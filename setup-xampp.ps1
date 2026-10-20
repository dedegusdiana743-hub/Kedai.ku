param(
    [string]$XamppRoot = 'C:\xampp',
    [string]$DbUser = 'root',
    [string]$DbPassword = '',
    [string]$DbName = 'pesanmenu'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $XamppRoot 'php\php.exe'
$mysql = Join-Path $XamppRoot 'mysql\bin\mysql.exe'
$mysqlAdmin = Join-Path $XamppRoot 'mysql\bin\mysqladmin.exe'
$schema = Join-Path $projectRoot 'database\schema.sql'

if (-not (Test-Path $php)) { throw "PHP tidak ditemukan: $php" }
if (-not (Test-Path $mysql)) { throw "MySQL tidak ditemukan: $mysql" }
if (-not (Test-Path $schema)) { throw "Schema tidak ditemukan: $schema" }

Write-Host '1/5 Menyalakan MySQL XAMPP...'
$mysqlProcess = Get-Process mysqld -ErrorAction SilentlyContinue
if (-not $mysqlProcess) {
    $mysqld = Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
    if (Test-Path $mysqld) {
        Start-Process -FilePath $mysqld -WindowStyle Hidden
        Start-Sleep -Seconds 5
    }
}

Write-Host '2/5 Menjalankan health check database...'
$envArgs = @('--protocol=TCP', "-u$DbUser")
if ($DbPassword) { $envArgs += "-p$DbPassword" }
& $mysql @envArgs -e 'SELECT VERSION();'
if ($LASTEXITCODE -ne 0) { throw 'MySQL belum dapat diakses. Pastikan MySQL XAMPP berjalan.' }

Write-Host '3/5 Mengimpor schema...'
& $mysql @envArgs -e "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw 'Membuat database gagal.' }
& $mysql @envArgs $DbName -e "source `"$schema`""
if ($LASTEXITCODE -ne 0) { throw 'Import schema gagal.' }

Write-Host '4/5 Menyiapkan file .env...'
$envFile = Join-Path $projectRoot '.env'
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $projectRoot '.env.example') $envFile
    $content = Get-Content $envFile -Raw
    $content = $content -replace 'DB_USERNAME=root', "DB_USERNAME=$DbUser"
    $content = $content -replace 'DB_PASSWORD=', "DB_PASSWORD=$DbPassword"
    $content = $content -replace 'DB_DATABASE=pesanmenu', "DB_DATABASE=$DbName"
    $content = $content -replace 'APP_URL=http://localhost:8080', 'APP_URL=http://localhost:8080'
    Set-Content -Path $envFile -Value $content -Encoding UTF8
    Write-Host "Dibuat: $envFile"
} else {
    Write-Host '.env sudah ada; tidak ditimpa.'
}

Write-Host '5/5 Membuat seller demo...'
$sellerEmail = 'penjual@example.com'
$sellerPassword = 'PasswordKuat123'
& $php (Join-Path $projectRoot 'bin\create-seller.php') "--email=$sellerEmail" "--password=$sellerPassword" '--name=KEDAI KU' '--slug=kedai-ku' '--whatsapp=08123456789' '--demo'
if ($LASTEXITCODE -ne 0) { throw 'Seller demo gagal dibuat. Hapus akun lama atau gunakan email lain.' }

Write-Host ''
Write-Host 'Setup selesai.'
Write-Host "URL aplikasi: http://localhost:8080"
Write-Host "Login: $sellerEmail / $sellerPassword"
Write-Host 'Untuk menjalankan server: .\scripts\start-local.ps1'