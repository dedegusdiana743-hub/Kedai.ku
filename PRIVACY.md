# Kebijakan Privasi (Draft)

> Dokumen ini adalah template operasional dan harus ditinjau penasihat hukum sebelum dipublikasikan.

## Data yang diproses

- Data akun penjual: nama, email, hash password, dan aktivitas operasional.
- Data pelanggan: nama, nomor telepon, alamat antar, catatan, dan detail pesanan.
- Data transaksi: nominal, status, waktu, dan referensi provider pembayaran.
- Data teknis: IP, log keamanan, dan audit trail.

## Tujuan

- Memproses pesanan, menghasilkan pembayaran, dan menyampaikan status pesanan.
- Mengamanan akun, mencegah fraud, dan memenuhi kewajiban operasional.
- Menyediakan laporan penjualan kepada penjual.

QRIS diproses melalui Midtrans/PSP yang dipilih. Data kartu atau kredensial pembayaran tidak disimpan oleh PesanMenu.

## Retensi

- Data pelanggan dianonimkan setelah `DATA_RETENTION_DAYS` (default 180 hari) dengan `bin/cleanup-retention.php`.
- Bukti transaksi agregat tetap diperlukan untuk pembukuan sesuai ketentuan yang berlaku.
- Token reset, throttle login, dan webhook operasional dibersihkan setelah masa retensinya.

## Hak pengguna

Administrator aplikasi harus menyediakan kanal untuk permintaan akses, koreksi, dan penghapusan data sesuai hukum yang berlaku. Sebelum menyelesaikan permintaan, verifikasi identitas penjual dan catat audit atas permintaan tersebut.

## Keamanan

- Password disimpan dengan `password_hash`.
- CSRF, session timeout, rate limit, output escaping, dan prepared statement.
- QRIS memakai verifikasi signature, validasi nominal, dan anti-replay.
- Backup harus dienkripsi dan tidak dapat diunduh dari document root.

## Kontak

Ganti bagian ini dengan identitas dan kontak administrator data yang sebenarnya sebelum go-live.