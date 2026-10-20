<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use PesanMenu\Core\App;

final class HealthService
{
    private const REQUIRED_TABLES = [
        'sellers', 'users', 'seller_users', 'categories', 'menu_items', 'orders',
        'order_items', 'payments', 'order_events', 'audit_logs', 'password_resets',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public function check(): array
    {
        $checks = [];
        $databaseOk = false;
        try {
            $this->app->db()->value('SELECT 1');
            $databaseOk = true;
            $checks[] = ['name' => 'database', 'ok' => true, 'required' => true, 'message' => 'Koneksi database tersedia.'];
        } catch (\Throwable) {
            $checks[] = ['name' => 'database', 'ok' => false, 'required' => true, 'message' => 'Database tidak dapat dihubungi.'];
        }

        if ($databaseOk) {
            try {
                $requiredTables = self::REQUIRED_TABLES;
                if ($this->app->paymentsEnabled()) {
                    $requiredTables = array_merge($requiredTables, ['payment_attempts', 'webhook_events', 'refunds']);
                }
                $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
                $found = (int) $this->app->db()->value(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($placeholders)",
                    $requiredTables
                );
                $ok = $found === count($requiredTables);
                $checks[] = ['name' => 'schema', 'ok' => $ok, 'required' => true, 'message' => $ok ? 'Schema inti tersedia.' : 'Tabel database belum lengkap. Jalankan migration.'];
            } catch (\Throwable) {
                $checks[] = ['name' => 'schema', 'ok' => false, 'required' => true, 'message' => 'Schema tidak dapat diverifikasi.'];
            }
        }

        $logsWritable = $this->canWrite($this->app->root() . '/storage/logs');
        $uploadsWritable = $this->canWrite($this->app->root() . '/storage/uploads');
        $checks[] = ['name' => 'storage', 'ok' => $logsWritable && $uploadsWritable, 'required' => true, 'message' => $logsWritable && $uploadsWritable ? 'Storage dapat ditulis.' : 'Direktori storage tidak writable.'];

        $production = $this->app->config('app.env') === 'production';
        $https = str_starts_with(strtolower((string) $this->app->config('app.url', '')), 'https://');
        $checks[] = ['name' => 'https', 'ok' => !$production || $https, 'required' => $production, 'message' => !$production || $https ? 'URL production aman.' : 'APP_URL production harus memakai HTTPS.'];
        $debug = (bool) $this->app->config('app.debug', false);
        $checks[] = ['name' => 'debug', 'ok' => !$production || !$debug, 'required' => $production, 'message' => !$production || !$debug ? 'Mode debug valid.' : 'APP_DEBUG harus false pada production.'];
        $mailDriver = (string) $this->app->config('mail.driver', 'log');
        $mailOk = !$production
            ? true
            : ($mailDriver === 'php' || ($mailDriver === 'smtp' && (string) $this->app->config('mail.smtp_host', '') !== ''));
        $checks[] = ['name' => 'mailer', 'ok' => $mailOk, 'required' => $production, 'message' => $mailOk ? 'Mailer dikonfigurasi.' : 'Production memerlukan MAIL_DRIVER=php atau smtp dengan host terpasang.'];
        if ($this->app->paymentsEnabled()) {
            $checks[] = ['name' => 'payments', 'ok' => true, 'required' => true, 'message' => 'QRIS dikonfigurasi.'];
        }

        $ok = true;
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    private function canWrite(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        $probe = $directory . DIRECTORY_SEPARATOR . '.health-' . bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, 'ok');
        if ($written !== false) {
            @unlink($probe);
        }
        return $written !== false;
    }
}