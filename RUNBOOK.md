# Runbook Operasional

## Health check

```bash
curl -fsS https://domain-anda/health
curl -fsS https://domain-anda/ready
php bin/health-check.php
```

`/health` adalah liveness check. `/ready` memeriksa database, schema, storage, HTTPS, debug mode, mailer, dan konfigurasi QRIS.

## Backup

```bash
php bin/backup-db.php --keep-days=14
```

Backup disimpan di `storage/backups` (di luar document root) disertai by checksum SHA-256. Backup harus disalin off-site dan dienkripsi.

## Retention

Jalankan dry-run lebih dulu:

```bash
php bin/cleanup-retention.php
php bin/cleanup-retention.php --apply
```

## Scheduled jobs

```cron
*/5 * * * * cd /path/pesanmenu && php bin/expire-orders.php
*/5 * * * * cd /path/pesanmenu && php bin/reconcile-payments.php
0 3 * * * cd /path/pesanmenu && php bin/cleanup-retention.php --apply
0 2 * * * cd /path/pesanmenu && php bin/backup-db.php --keep-days=14
```

## Security headers

`src/Core/App.php` mengirim `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, dan `Strict-Transport-Security`. HSTS mengikuti scheme request; di belakang reverse proxy/tunnel, izinkan `X-Forwarded-Proto` hanya dengan `TRUST_PROXY=true` dan gunakan allowlist `PUBLIC_HOST` agar Host header tidak dapat poisoning link.

## Payment mismatch

1. Jangan mengubah status pembayaran secara manual di browser.
2. Periksa `payment_attempts`, `payments`, `webhook_events`, dan audit log.
3. Jalankan `php bin/reconcile-payments.php`.
4. Jika nominal atau status tidak cocok, escalate ke provider dan withhold refund/fulfillment.

## Refund ambiguous

Refund `PENDING` dengan `provider_called=1` tidak boleh diulang. Reconciliation akan mencocokkan refund berdasarkan provider reference, nominal, dan waktu. Jika masih `PENDING` setelah 24 jam, periksa settlement provider secara manual sebelum membuka refund baru.

## Backup incident

Jika restore diperlukan, gunakan procedures restore yang disetujui, verifikasi jumlah order, payment, refund, dan audit log, lalu catat incident/postmortem.