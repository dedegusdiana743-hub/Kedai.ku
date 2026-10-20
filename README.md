# PesanMenu

Aplikasi web mobile untuk pemesanan makanan dan minuman tanpa login pelanggan. Pelanggan membuka link toko, memilih menu, dan melakukan checkout; penjual mengelola toko, menu, dan pesanan dari dashboard.

> **Status:** MVP aman untuk demonstrasi/internal. Rilis publik memerlukan HTTPS, backup, monitoring, security review, dan payment integration produksi. Rilis awal sengaja menggunakan **COD-only**.

## Kebutuhan

- PHP 8.1+ dengan ekstensi `pdo_mysql`, `mbstring`, `fileinfo`, dan `session`
- MySQL 8.0+ atau MariaDB yang mendukung `CHECK`/komposit foreign key
- Apache dengan `mod_rewrite`, atau PHP built-in server untuk pengembangan

## Fitur

- Link toko berbasis slug (`/toko/{slug}`)
- Katalog kategori, menu, harga, dan ketersediaan
- Keranjang server-side per toko
- Checkout dengan perhitungan harga di server
- COD dengan status pesanan dan audit trail
- Tracking pesanan memakai token acak
- Login penjual, membership, dan pembatasan data per toko
- CRUD menu, pesanan, laporan, dan pengaturan toko
- PDO prepared statements, CSRF, output escaping, validasi, dan secure session baseline

Role dashboard: `OWNER`/`MANAGER` dapat mengelola katalog dan pengaturan; `CASHIER` dapat operasional pesanan dan COD; `COURIER` dapat memperbarui status pengantaran.

## Menjalankan dengan XAMPP

1. Pastikan XAMPP terinstal dan MySQL berjalan.
2. Jalankan setup otomatis (untuk XAMPP di `D:\xampp`):

```powershell
.\scripts\setup-xampp.ps1 -XamppRoot D:\xampp
```

Script akan membuat database, mengimpor schema, membuat `.env`, dan membuat seller demo.

3. Jalankan server lokal:

```powershell
.\scripts\start-local.ps1 -XamppRoot D:\xampp
```

4. Buka:

```text
http://localhost:8080
http://localhost:8080/penjual/login
```

Untuk HP pada Wi-Fi yang sama, jalankan:

```powershell
.\scripts\start-local.ps1 -XamppRoot D:\xampp -SetPublicUrl -AllowFirewall
```

Lalu buka alamat IP LAN yang ditampilkan, contoh:

```text
http://192.168.1.3:8080/toko/kedai-ku
```

HP dan komputer harus berada pada Wi-Fi/Jaringan yang sama. Jangan kirim URL `localhost` ke HP; localhost selalu mengarah ke HP itu sendiri.

Jika Wi-Fi berstatus `Public`, buka PowerShell sebagai Administrator lalu jalankan:

```powershell
Set-NetConnectionProfile -InterfaceAlias "Wi-Fi" -NetworkCategory Private
```

Kemudian jalankan ulang:

```powershell
.\scripts\start-local.ps1 -XamppRoot D:\xampp -SetPublicUrl -AllowFirewall
```

Login demo:

```text
penjual@example.com / PasswordKuat123
```

Untuk Homestead, Valet, atau XAMPP di lokasi lain, sesuaikan `-XamppRoot` atau jalankan langkah manual: import `database/schema.sql`, salin `.env.example` ke `.env`, lalu gunakan `bin/create-seller.php`.

## Menjalankan dengan Docker

```bash
docker compose up -d --build
docker compose exec app php bin/create-seller.php --email=penjual@example.com --password="PasswordKuat123" --name="KEDAI KU" --slug=kedai-ku --whatsapp=08123456789 --demo
```

Buka `http://localhost:8080`.

Jika volume MySQL sudah pernah dibuat, `schema.sql` tidak dijalankan ulang. Untuk development yang boleh dihapus, gunakan `docker compose down -v` lalu `docker compose up -d --build`. Jangan menghapus volume pada data produksi. Untuk instalasi lama, jalankan `database/migrations/001_production_baseline.sql`, lalu `database/migrations/002_qris.sql` pada database yang relevan.

## Integration test

Test otomatis dengan MySQL:

```bash
php tests/run.php
RUN_INTEGRATION=1 php tests/integration.php
```

Tanpa `RUN_INTEGRATION=1`, integration test dilewati secara aman.

## Operasional

```bash
php bin/health-check.php
php bin/backup-db.php --keep-days=14
php bin/cleanup-retention.php
```

Retention memakai dry-run secara default; tambahkan `--apply` hanya setelah review hasil. `docs/RUNBOOK.md` berisi jadwal cron, backup, restore, dan incident payment.

## Perintah operasional

Perintah berikut disiapkan untuk fase QRIS dan hanya memproses order `PENDING_PAYMENT`. Pada MVP COD-only, hasilnya biasanya `0`:

```bash
php bin/expire-orders.php
```

Jalankan melalui cron setiap beberapa menit setelah QRIS produksi diaktifkan:

```cron
*/5 * * * * cd /path/pesanmenu && php bin/expire-orders.php >> storage/logs/cron.log 2>&1
*/5 * * * * cd /path/pesanmenu && php bin/reconcile-payments.php >> storage/logs/cron.log 2>&1
```

`/health` untuk liveness dan `/ready` untuk readiness (database, schema, storage, HTTPS, debug, mailer, dan QRIS).

## Email dan session

`MAIL_DRIVER=log` hanya untuk development; tautan reset ditulis ke `storage/logs/mail.log`. Untuk production gunakan driver `php` dengan MTA terpasang atau driver `smtp`/`SMTP_*` dengan `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, dan `SMTP_ENCRYPTION`. `SESSION_IDLE_TIMEOUT` dan `SESSION_ABSOLUTE_TIMEOUT` default 1 jam dan 12 jam.

## Link lokal stabil (tidak publik)

Agar tidak perlu meminta link baru setiap kali, buat hostname lokal sekali saja:

```powershell
.\scripts\setup-local-host.ps1
```

Script ini menambahkan `127.0.0.1 kedaiku.local` ke hosts file (minta izin Administrator) dan menyiapkan `.env`. Setelah itu link tidak berubah:

```text
http://kedaiku.local:8080/toko/kedai-ku
http://kedaiku.local:8080/penjual/login
```

Buka cepat dengan:

```powershell
.\scripts\open-kedaiku.ps1
```

Untuk HP, pakai alamat IP LAN komputer (tippetapkan DHCP reservation di router agar IP tidak berubah):

```text
http://IP-KOMPUTER:8080/toko/kedai-ku
```

## Link publik sementara

Untuk akses dari internet tanpa domain permanen:

```powershell
winget install --id Cloudflare.cloudflared --exact
.\scripts\start-local.ps1 -XamppRoot D:\xampp
.\scripts\start-tunnel.ps1
```

URL `trycloudflare.com` diambil dari `storage/logs/cloudflared.url` (log mentah ada di `cloudflared.log`/`.err`). URL berubah setiap tunnel dimulai ulang. Jangan memakai akun demo atau kredensial produksi pada link publik tanpa mengganti password dan mengaktifkan HTTPS/tunnel yang stabil.

## QRIS (Midtrans)

QRIS memakai Core API QRIS Midtrans dengan QR dinamis per transaksi. `QR Server API` hanya merender QR dari string dan **bukan** payment gateway.

Aktifkan setelah account production/sandbox, webhook publik HTTPS, dan UAT tersedia:

```env
PAYMENT_MODE=QRIS
MIDTRANS_SERVER_KEY=server-key-yang-rahasia
MIDTRANS_PRODUCTION=false
MIDTRANS_ACQUIRER=gopay
```

- `false` memakai `api.sandbox.midtrans.com`; `true` memakai endpoint production.
- Notification URL: `https://domain-anda/payment/midtrans/notification`
- Jalankan `php bin/expire-orders.php` dan `php bin/reconcile-payments.php` secara berkala.
- Callback diverifikasi dengan `signature_key`, divalidasi terhadap nominal/currency, dan diproses idempotent.
- Refund penuh/sebagian diproses dari detail pesanan oleh `OWNER`/`MANAGER`.

Jangan menaruh server key di repository, JavaScript, atau URL. Gunakan secret storage server.

## Struktur singkat

- `public/`: document root
- `src/Core/`: konfigurasi, database, router, auth, view
- `src/Controllers/`: alur pelanggan dan penjual
- `src/Services/`: cart, pricing, order, upload
- `views/`: template HTML
- `database/schema.sql`: schema MySQL
- `bin/`: utilitas CLI
- `scripts/`: setup XAMPP dan server lokal Windows
- `tests/`: unit test (`run.php`) dan integration test opsional (`integration.php`)
- `docs/RUNBOOK.md`: health, backup, incident, dan scheduled jobs
- `docs/RESTORE.md`: prosedur restore yang aman
- `docs/PRIVACY.md`: template privasi dan retention
- `deploy/`: contoh deployment VPS Ubuntu, Nginx, HTTPS, dan systemd timer
