# Status Implementasi PesanMenu

**Status:** MVP fungsional untuk demo/internal — **belum boleh dinyatakan go-live publik**.

## Yang sudah diimplementasikan

- Multi-toko dengan `slug` dan seller-scoped query.
- Login penjual dengan password hash, session hardening, CSRF, dan rate limit login berbasis database.
- Role `OWNER`, `MANAGER`, `CASHIER`, `COURIER` dengan pembatasan menu, kategori, pengaturan, dan pembayaran.
- Katalog kategori makanan/minuman, upload gambar divalidasi, dan soft delete menu.
- Keranjang server-side per toko; harga, pajak, ongkir, dan total dihitung ulang di server.
- Checkout COD dalam satu database transaction dengan `FOR UPDATE` dan idempotency key.
- Status pesanan, timeline, audit log, dan konfirmasi COD dibayar.
- Tracking dengan token 256-bit; hanya hash token yang disimpan.
- Adapter Midtrans QRIS: charge dinamis, halaman QR, polling status, webhook signature, rekonsiliasi, expiry, serta refund penuh/sebagian.
- Laporan 30 hari berdasarkan `payments.paid_at`.
- Pengaturan toko, ongkir, pajak, dan status aktif.
- Password reset via email (log/PHP/SMTP), ganti password, session idle/absolute timeout, dan invalidasi token_version.
- Health/readiness check, backup gzip + checksum, retention/anonymization, dan runbook operasional.
- Error page 403/404/405/419/500, security headers dasar, dan UI responsif tanpa CDN.
- `Dockerfile`, `docker-compose.yml`, schema MySQL 8, CLI pembuat seller, dan test ringan.

## Validasi yang dijalankan

- `php -l` pada 72 file PHP: lulus.
- `php tests/run.php`: lulus, termasuk verifikasi signature Midtrans.
- `tests/integration.php`: lulus pada MariaDB lokal; menguji tenant isolation, COD, idempotency, dan cleanup.
- XAMPP 8.2.12 + MariaDB 10.4.32 lokal: database `pesanmenu` dibuat dan schema diimpor.
- Smoke HTTP lokal: login, dashboard, toko, keranjang, checkout, dan success page lulus.
- Smoke render view publik, QRIS, dan refund: lulus.
- Validasi statis schema MySQL 8: 15 tabel, 0 error foreign key/constraint.

## Menjalankan

### XAMPP

1. Salin `.env.example` ke `.env`.
2. Atur `DB_*`, `APP_URL`, dan `DB_TIMEZONE=+07:00`.
3. Import `database/schema.sql` ke MySQL.
4. Arahkan document root Apache ke `public/`.
5. Jalankan:

```bash
php bin/create-seller.php --email=penjual@example.com --password="PasswordKuat123" --name="KEDAI KU" --slug=kedai-ku --whatsapp=08123456789 --demo
```

### Docker

```bash
docker compose up -d --build
```

Untuk volume MySQL lama, `schema.sql` tidak dijalankan ulang. Gunakan migration/manual `ALTER` atau, khusus data development, `docker compose down -v`.

## Yang masih menjadi blocker go-live publik

- Domain dan HTTPS production.
- Kredensial dan akun Midtrans production/sandbox, callback publik HTTPS, UAT pembayaran, serta go-live approval provider.
- Konfigurasi mailer production; `MAIL_DRIVER=log` hanya development karena menyimpan tautan reset di disk.
- Runbook manual untuk refund ambigu yang tetap `PENDING` setelah 24 jam; sistem sengaja tidak membuka refund kedua secara otomatis.
- UAT pelanggan/penjual, load test, security test, dan backup-restore drill.
- Monitoring, alerting, incident runbook, retention/privacy notice, dan kebijakan refund/COD.
- Rate limit berbasis proxy yang dikonfigurasi bila aplikasi berada di belakang reverse proxy.
- Migration versioning untuk instalasi yang sudah ada.

QRIS sengaja dinonaktifkan secara default sampai kredensial provider, webhook HTTPS, dan UAT tersedia. `QR Server API` bukan payment gateway dan tidak boleh dianggap sebagai bukti pembayaran.

## Checklist sebelum demo

- [ ] Import schema dan jalankan test.
- [ ] Buat seller dan login.
- [ ] Buat kategori, menu, lalu uji link toko.
- [ ] Uji keranjang, pickup, antar, dan COD.
- [ ] Uji status pembayaran dan tracking token.
- [ ] Uji bahwa seller lain tidak dapat melihat ID menu/pesanan toko pertama.