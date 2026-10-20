# Restore Database (Runbook)

Restore bersifat destruktif. Lakukan hanya setelah persetujuan owner, di environment staging/isolated, dan dengan backup terbaru yang sudah diuji.

## Verifikasi backup

1. Lihat file `storage/backups/pesanmenu-*.sql.gz`.
2. Verifikasi checksum:

```bash
sha256sum -c storage/backups/pesanmenu-YYYYMMDD-HHMMSS.sql.gz.sha256
```

3. Decompress ke file SQL sementara dan review header/ukuran.

## Restore dengan MySQL

```bash
gunzip -c backup.sql.gz | mysql -h HOST -u USER -p pesanmenu
```

Gunakan password melalui secret manager atau `MYSQL_PWD` pada environment terisolasi; jangan menaruh password di command history.

## Setelah restore

- Jalankan `php bin/health-check.php`.
- Verifikasi jumlah `orders`, `payments`, `refunds`, dan `order_events`.
- Jalankan UAT login, checkout COD, tracking, dan (jika dikonfigurasi) QRIS sandbox.
- Catat hasil restore dan Incident ID.