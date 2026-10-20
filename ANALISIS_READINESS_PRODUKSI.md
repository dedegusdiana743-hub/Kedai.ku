# Analisis Production Readiness — PesanMenu

**Dokumen sumber:** `Laporan Blueprint_Dede Gusdiana Cipta(60225074) (1).docx`  
**Pemilik dalam dokumen:** Dede Gusdiana Cipta / NIM 60225074  
**Tanggal audit:** 25 September 2026  
**Ruang lingkup:** audit blueprint; kode aplikasi, server, database, pengujian, dan deployment belum tersedia.
**Status implementasi:** MVP COD-only sudah dibuat; lihat `STATUS_IMPLEMENTASI.md` untuk hasil dan blocker yang tersisa.

## 1. Kesimpulan Eksekutif

### Putusan: **NO-GO untuk produksi publik**

Blueprint sudah memadai sebagai **konsep akademik/prototipe**, tetapi belum memenuhi syarat sebagai aplikasi produksi. Struktur utama sudah mengarah pada multi-toko: setiap menu dan pesanan memiliki `penjual_id`, pelanggan dapat memesan melalui link tanpa login, dan detail pesanan sudah menyimpan snapshot nama serta harga.

Namun, masih ada beberapa launch blocker yang harus diselesaikan:

1. Belum ada desain akun, login, dan kontrol akses penjual yang aman.
2. Belum ada aturan isolasi data antarpenjual; berisiko IDOR/data leakage.
3. Foreign key, unique, index, check constraint, dan transaksi database belum dirancang.
4. Harga dan total pada keranjang dapat dimanipulasi jika tidak dihitung ulang di server.
5. Pembuatan pesanan belum memiliki idempotency dan perlindungan race condition.
6. QRIS masih berupa Sandbox/QR statis dan belum memiliki webhook, rekonsiliasi, maupun refund.
7. State pesanan dan pembayaran belum memiliki transisi yang aman.
8. Belum ada strategi keamanan aplikasi, privasi, testing, monitoring, backup, dan pemulihan.
9. Arsitektur `localhost`/XAMPP/phpMyAdmin hanya sesuai untuk pengembangan.

**Rekomendasi:** tetapkan rilis pertama sebagai **COD-only** untuk menekan risiko. Tambahkan QRIS setelah alur pembayaran produksi, webhook, rekonsiliasi, refund, dan pengujiannya selesai.

## 2. Ringkasan Readiness

| Area | Status | Penilaian |
|---|---|---|
| Scope dan alur pelanggan | Amber | Alur utama tersedia, tetapi accept/reject/cancel/refund belum lengkap. |
| Katalog dan keranjang | Amber | Struktur cukup, tetapi validasi server belum menjadi syarat. |
| Dashboard penjual | Merah | Login, otorisasi, notifikasi, dan audit belum dirancang. |
| Database | Amber–Merah | Entitas dasar ada, tetapi integritas relasional belum dirancang. |
| Pembayaran | Merah | Sandbox dan QR statis belum layak produksi. |
| Keamanan | Merah | CSRF, XSS, SQL injection, IDOR, session, dan upload belum ditangani. |
| UX/Aksesibilitas | Merah | Daftar halaman ada, tetapi state exceptional dan standar aksesibilitas belum dirancang. |
| Testing dan QA | Merah | Tidak ada test plan atau acceptance criteria. |
| Deployment dan operasi | Merah | Masih menggunakan localhost/XAMPP; monitoring dan backup belum ada. |
| Privasi dan kepatuhan | Merah | Data pelanggan dikumpulkan tanpa kebijakan retensi dan perlindungan. |

## 3. Bagian yang Sudah Bagus

- Gunakan URL toko berbasis slug agar mudah dibagikan.
- Pelanggan tidak perlu membuat akun, sesuai pola UMKM sederhana.
- Status pembayaran dipisahkan dari status fulfilment pesanan.
- `nama_menu` dan `harga` pada `detail_pesanan` merupakan snapshot yang benar.
- Rumus dasar harga sudah dijelaskan.
- Seller dan pelanggan memiliki halaman yang relatif terpisah.
- Model konseptual cukup mudah dikembangkan untuk aplikasi PHP Native.

Hal-hal tersebut harus dipertahankan saat blueprint direvisi.

## 4. Launch Blocker

| ID | Temuan | Risiko | Perbaikan wajib |
|---|---|---|---|
| P0-01 | `localhost` dan XAMPP | Pelanggan tidak dapat mengakses aplikasi | Domain, HTTPS, server, dan environment production |
| P0-02 | Tabel akun/credential penjual tidak ada | Siapa pun yang menemukan dashboard dapat login atau mengubah data | User authentication, password hash, session, RBAC |
| P0-03 | Otorisasi tenant tidak ada | Penjual A dapat mengakses data penjual B | Membership dan seller-scoped authorization |
| P0-04 | Harga dari browser berpotensi dipercaya | Manipulasi subtotal/pajak/total | Hitung ulang seluruh nominal di server |
| P0-05 | Tidak ada foreign key/constraint | Data yatim, tenant campur, kode duplikat | FK, unique, index, dan check constraint |
| P0-06 | Checkout belum atomik | Pesanan ada tetapi detail/payment gagal | Database transaction |
| P0-07 | Double submit belum ditangani | Pesanan duplikat | Idempotency key dan transaction isolation |
| P0-08 | QRIS bukan payment flow produksi | Pelanggan membayar tetapi sistem tidak memastikan | Official production integration, webhook, reconciliation |
| P0-09 | Tracking dapat memakai kode pendek | Data pelanggan dapat dijelajahi | Random tracking token atau OTP |
| P0-10 | Security baseline tidak ada | SQLi, XSS, CSRF, BOLA, upload attack | Security controls dan pengujiannya |
| P0-11 | Tidak ada backup/monitoring | Data hilang atau kegagalan tidak terdeteksi | Logging, metrics, alert, backup, dan restore drill |
| P0-12 | Tidak ada test/UAT | Kesalahan dapat mencapai pelanggan | Automated tests, UAT, dan release gate |

## 5. Keputusan Bisnis yang Harus Ditetapkan Sebelum Coding

Jawab dan dokumentasikan keputusan berikut:

1. Apakah rilis pertama wajib memakai QRIS, atau COD dapat menjadi metode pertama?
2. Apakah penjual melayani antar dan ambil sendiri?
3. Ongkos kirim flat atau berdasarkan jarak/kurir?
4. Apakah PPN selalu 10%, dan bagaimana pembulatan jumlah rupiah?
5. Apakah stok membatasi pesanan atau menu dibuat saat pesanan diterima?
6. Kapan pesanan dianggap kadaluarsa?
7. Apakah pelanggan dapat membatalkan? Sampai kapan?
8. Kapan seller dapat menolak/membatalkan pesanan?
9. Bagaimana COD dikonfirmasi dan dicatat?
10. Apakah refund QRIS tersedia pada versi pertama?
11. Apakah satu toko dapat memiliki beberapa operator/kasir/kurir?
12. Berapa lama data pelanggan disimpan?

## 6. Arsitektur Produksi yang Direkomendasikan

Arsitektur monolit PHP masih dapat digunakan, tetapi harus memiliki boundary yang jelas:

```text
Customer / Seller Browser
          |
       HTTPS
          |
 Reverse Proxy / Web Server
          |
 PHP Application
  - Public Controller
  - Seller/Admin Controller
  - Authentication Middleware
  - Tenant Authorization Middleware
  - CSRF Middleware
  - Service Layer
  - Payment Adapter (Midtrans/PSP)
  - Notification Adapter (WhatsApp/email)
          |
       MySQL
          |
 Scheduled Jobs
  - payment expiry
  - reconciliation
  - notification retry
```

Struktur kode minimal:

```text
app/
  Core/          # config, database, router, security, logging
  Controllers/   # public, auth, seller, payment
  Middleware/
  Models/
  Repositories/
  Services/      # order, pricing, payment, notification
  Views/
  Jobs/
config/
database/migrations/
public/          # satu-satunya folder yang dapat diakses web
storage/logs/
tests/
```

`public_html`, `storage`, konfigurasi, dan `.env` tidak boleh dapat diunduh langsung. Asset frontend sebaiknya dikompilasi dan disajikan sendiri, bukan dimuat dari CDN pada release produksi.

## 7. Perbaikan Model Data

### 7.1 Identity dan tenant

Tambahkan:

- `users`: identitas login, email/nomor telepon, password hash, status, token version.
- `tenant_memberships`: hubungan user dengan penjual/toko dan role seperti `OWNER`, `CASHIER`, atau `COURIER`.
- `sessions` atau session storage yang aman.
- `password_reset_tokens`: token acak, hash, expiry, dan status pemakaian.

Tabel `penjual` tetap menjadi profil toko, bukan tabel login.

### 7.2 Constraint wajib

- `UNIQUE(penjual.slug)`.
- `UNIQUE(pesanan.kode)`.
- Foreign key dari kategori, menu, detail pesanan, dan pembayaran.
- `menu.kategori_id` harus konsisten dengan `menu.penjual_id`.
- Harga, kuantitas, ongkir, pajak, dan total tidak boleh negatif.
- `jumlah > 0`.
- `total = subtotal + ongkir + pajak`.
- `subtotal = harga_satuan * jumlah` pada setiap detail.
- Gunakan `BIGINT` untuk nominal agar total besar tidak mudah overflow.
- Gunakan snapshot nama, harga, tarif pajak, dan ongkir pada order.
- Tambahkan `updated_at`, alasan pembatalan, dan version/status history.
- Jangan menghapus menu yang pernah dipesan; gunakan `deleted_at` atau pembatasan delete.

Untuk keamanan tenant, gunakan composite key, misalnya menu dan kategori harus memiliki pasangan unik `(id, penjual_id)` dan foreign key komposit. Ini mencegah kategori toko A digunakan oleh menu toko B.

### 7.3 Tabel tambahan

- `riwayat_status_pesanan`: status lama, status baru, actor, alasan, waktu.
- `percobaan_pembayaran`: QR dapat dicoba ulang tanpa merusak histori.
- `refunds`: full/partial refund, provider ID, jumlah, status, alasan, actor.
- `webhook_events`: anti-replay, signature result, payload hash, dan hasil proses.
- `audit_logs`: login, perubahan menu, harga, status, pembayaran, dan refund.
- `inventory` dan `inventory_movements` jika aplikasi mengelola jumlah stok.
- `checkout_sessions` atau key idempotency untuk mencegah pesanan ganda.

## 8. Status Pesanan dan Pembayaran

### Status pesanan

```text
PENDING_PAYMENT -> PENDING_CONFIRMATION -> ACCEPTED
PENDING_PAYMENT / PENDING_CONFIRMATION -> REJECTED | CANCELLED | EXPIRED
ACCEPTED -> PREPARING
PREPARING -> READY_FOR_PICKUP -> COMPLETED
PREPARING -> READY_FOR_DELIVERY -> OUT_FOR_DELIVERY -> COMPLETED
```

- `CANCELLED`, `REJECTED`, dan `EXPIRED` adalah terminal.
- Paid QRIS yang dibatalkan harus melalui resolution/refund, tidak boleh langsung menjadi cancelled.
- COD hanya ditandai paid setelah diterima atau dicatat oleh seller yang berwenang.
- Setiap perubahan status menggunakan validasi state saat ini dan disimpan pada audit trail.

### Status pembayaran

```text
PENDING -> PAID | FAILED | EXPIRED | CHALLENGED
CHALLENGED -> PAID | FAILED
PAID -> REFUND_PENDING -> PARTIALLY_REFUNDED | REFUNDED
```

Status pembayaran tidak boleh ditentukan oleh halaman sukses browser. Sumber kebenaran adalah callback terverifikasi atau hasil query resmi ke payment provider.

## 9. Alur Checkout yang Benar

1. Pelanggan membuka toko berdasarkan slug yang valid dan aktif.
2. Keranjang browser hanya menyimpan ID menu, quantity, dan identifiers. ID toko dan session seller ditambahkan dari server.
3. Saat membuka keranjang atau checkout, server mengambil harga, kategori, status tersedia, dan seller dari database.
4. Server menampilkan perubahan harga/stok kepada pelanggan jika diperlukan.
5. Pelanggan memilih antar atau ambil sendiri serta mengisi data.
6. Server memvalidasi format telepon, panjang data, dan aturan pengiriman.
7. Server membuat idempotency key untuk mencegah double submit.
8. Dalam satu database transaction, server:
   - mengecek menu aktif dan milik toko yang benar;
   - mengecek stok atau aturan made-to-order;
   - menghitung subtotal, ongkir, pajak, dan total;
   - membuat order serta order items;
   - membuat payment attempt bila QRIS;
   - membuat status history dan audit log;
   - commit.
9. Untuk COD, pesanan masuk sebagai `PENDING_CONFIRMATION`.
10. Untuk QRIS, server meminta QR dinamis dari provider setelah commit lokal.
11. Halaman sukses hanya menampilkan hasil; pembayaran tidak dianggap paid sampai webhook atau query provider terverifikasi.

## 10. Implementasi QRIS Produksi

`QR Server API` hanya menghasilkan QR dari string. QR statis tidak membuktikan bahwa pembayaran untuk order tertentu sudah masuk.

Untuk QRIS production:

- gunakan akun dan API provider resmi dalam mode production;
- server key hanya berada di secret manager/server, tidak di JavaScript;
- QR harus unik dan memiliki nominal/order yang sesuai;
- callback harus memverifikasi signature;
- validasi provider order ID, merchant, nominal, currency, dan status;
- simpan event secara idempotent untuk mencegah replay;
- jika callback hilang, scheduled job melakukan reconciliation;
- sediakan retry pembayaran, expiry, failure, cancellation, full/partial refund;
- lakukan smoke test dan refund test dengan nominal kecil;
- jangan memakai Midtrans Sandbox untuk transaksi pelanggan nyata.

## 11. Baseline Keamanan

- Gunakan PDO/prepared statement untuk semua query.
- Escape output berdasarkan konteks untuk mencegah XSS.
- Wajibkan CSRF token untuk semua operasi mutasi, termasuk guest checkout.
- Set cookie `HttpOnly`, `Secure`, dan `SameSite=Lax` atau yang sesuai.
- Hash password dengan `password_hash()` dan cost yang disetujui.
- Batasi percobaan login, gunakan account recovery, dan rotasi session setelah login.
- Semua query seller harus memiliki membership check; jangan percaya `penjual_id` dari request.
- Lacak pesanan memakai token acak minimal 128 bit atau OTP, bukan ID berurutan.
- Validasi upload: MIME aktual, ukuran, dimensi, nama acak, lokasi non-executable, dan anti-malware bila tersedia.
- Terapkan rate limit, request-size limit, security headers, HTTPS, dan HSTS.
- Jangan menampilkan stack trace, query, atau secret pada error production.
- Gunakan log correlation ID dan jangan log password, session token, atau payload pembayaran sensitif.
- Dependency harus memiliki versi terkunci dan dipindai rutin.

## 12. UX Minimum

Setiap halaman harus menangani:

- loading;
- data kosong;
- error dan retry;
- offline/jaringan lambat;
- session timeout;
- permission denied;
- toko tidak ditemukan/nonaktif;
- menu habis atau berubah harga;
- QRIS pending, failed, expired;
- seller reject/cancel;
- COD belum dibayar;
- refund/resolution.

Halaman lacak harus menampilkan timeline status, nomor pesanan, estimasi, dan kontak seller tanpa mengekspos PII milik pelanggan lain.

Dashboard penjual perlu memiliki:

- notifikasi pesanan masuk;
- accept/reject order;
- pencarian, filter, pagination;
- detail item dan snapshot harga;
- audit status;
- konfirmasi pembayaran COD;
- ringkasan omzet dengan definisi yang jelas;
- ketersediaan menu dan jam buka.

Form perlu memiliki label, validasi inline, fokus keyboard, kontras memadai, dan dukungan screen reader untuk alur inti.

## 13. Test dan Acceptance Criteria

### Fungsional wajib

- [ ] Seller A tidak dapat melihat atau mengubah data seller B.
- [ ] Harga, quantity, ongkir, pajak, dan total dari client diabaikan.
- [ ] Harga atau menu berubah di tengah checkout dan pelanggan diminta konfirmasi.
- [ ] Double click/tap tidak membuat dua order.
- [ ] Transaksi database gagal sepenuhnya jika salah satu insert gagal.
- [ ] Slug invalid, duplikat, dan toko nonaktif ditangani.
- [ ] COD memiliki accept/reject, pembayaran, dan pembatalan.
- [ ] Status transition valid dan tidak valid diuji.
- [ ] Tracking token milik pelanggan tidak dapat dibuka tanpa otorisasi.
- [ ] Tombol dan halaman seller sesuai role yang benar.

### Pembayaran

- [ ] QRIS sukses, pending, failed, expired, retry, dan timeout.
- [ ] Invalid signature ditolak.
- [ ] Nominal atau currency tidak sama ditolak.
- [ ] Callback duplikat tidak mengubah pembayaran dua kali.
- [ ] Callback hilang ditemukan oleh reconciliation.
- [ ] Full/partial refund tercatat dan dapat diaudit.

### Keamanan dan nonfungsional

- [ ] SQL injection, XSS, CSRF, IDOR, brute force, dan file upload diuji.
- [ ] Android Chrome dan iOS Safari diuji.
- [ ] Jaringan lambat/offline diuji.
- [ ] Load test sesuai target puncak.
- [ ] Dependency dan runtime vulnerability scan.
- [ ] Backup berhasil dan restore diuji.
- [ ] UAT pelanggan, penjual, dan pemilik proses disetujui.

## 14. Deployment dan Operasional

- Environment `development`, `staging`, dan `production` terpisah.
- Domain dan HTTPS production.
- PHP dan MySQL versi dikunci.
- `.env` dan secret berada di luar repository.
- phpMyAdmin tidak diekspos ke internet.
- Migration versioned dan rollback terdokumentasi.
- Structured log, request ID, metrics, uptime check, dan alert.
- Alert untuk order gagal, payment mismatch, webhook failure, database error, disk, dan certificate expiry.
- Backup otomatis terenkripsi, off-site, dengan retention yang disepakati.
- Restore drill berkala; backup success saja belum cukup.
- Tentukan RPO/RTO, incident owner, support channel, dan runbook.
- Review berkala atas defect, payment mismatch, refund, fraud, dan support ticket.

## 15. Tahapan Pengerjaan

### Fase 1 — Fondasi

1. Tetapkan scope COD-only atau QRIS.
2. Finalisasi business rules dan state machine.
3. Buat user, tenant membership, migration, dan constraint database.
4. Buat authentication, tenant authorization, CSRF, prepared statements, dan audit log.

### Fase 2 — CORE ORDER

1. Katalog, seller scope, dan availability.
2. Keranjang guest dengan server-side revalidation.
3. Checkout atomik dan idempotent.
4. Order status timeline, seller accept/reject, dan COD settlement.
5. Tracking dengan token acak.

### Fase 3 — PAYMENT (Jika Diperlukan)

1. Integrasi provider produksi.
2. Webhook, reconciliation, expiry, retry, dan refund.
3. Dashboard payment dan operational alert.
4. Smoke test dan UAT pembayaran.

### Fase 4 — HARDENING

1. Security, load, mobile, accessibility, dan disaster recovery test.
2. Monitoring, backup/restore drill, incident runbook.
3. Fix semua defect Severity 1/2.
4. Go-live approval.

## 16. Go-Live Gate

Aplikasi baru dapat dinyatakan siap produksi bila:

- [ ] Semua P0 sudah ditutup dan memiliki bukti.
- [ ] UAT pelanggan dan penjual lulus.
- [ ] Tidak ada defect Severity 1/2 terbuka.
- [ ] Multi-tenant isolation dan security test lulus.
- [ ] Checkout idempotent dan database transaction teruji.
- [ ] QRIS memiliki webhook, reconciliation, dan refund; atau QRIS dimatikan.
- [ ] HTTPS, secret management, migration, rollback, dan phpMyAdmin hardening selesai.
- [ ] Monitoring, alerting, backup, dan restore drill aktif.
- [ ] Privacy notice, retention policy, dan prosedur refund/cancellation disetujui.
- [ ] Go-live checklist ditandatangani oleh owner aplikasi dan pemilik proses.

## 17. Kesimpulan Akhir

Blueprint tersebut **bagus sebagai fondasi tugas mahasiswa atau demo**, tetapi statusnya **belum siap produksi**. Prioritas utama bukan menambah fitur, melainkan menutup celah identitas, otorisasi, integritas transaksi, pembayaran, keamanan, dan operasi.

Jika target awal adalah UMKM kecil dengan risiko dan kompleksitas minimum, **rilis COD-only** adalah pilihan paling aman. QRIS harus menjadi fase kedua setelah payment flow produksi dan seluruh kontrolnya diuji.
