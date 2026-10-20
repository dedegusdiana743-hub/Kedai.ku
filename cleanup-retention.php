<?php

declare(strict_types=1);

/**
 * Anonymize old customer PII and purge expired security records.
 * Default: dry-run. Use --apply to persist.
 */

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$options = getopt('', ['days:', 'apply']);
$days = max(30, (int) ($options['days'] ?? $app->config('app.retention_days', 180)));
$apply = array_key_exists('apply', $options);

$cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
$orders = $app->db()->all(
    "SELECT id, seller_id FROM orders
     WHERE created_at < ? AND customer_name <> 'Pelanggan dihapus'",
    [$cutoff]
);
$expiredResets = (int) $app->db()->value('SELECT COUNT(*) FROM password_resets WHERE expires_at < NOW() - INTERVAL 30 DAY');
$expiredLogins = (int) $app->db()->value('SELECT COUNT(*) FROM login_attempts WHERE updated_at < NOW() - INTERVAL 30 DAY');

echo "Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "PII pesanan yang akan dianonimkan: " . count($orders) . "\n";
echo "Token reset kedaluwarsa: {$expiredResets}\n";
echo "Riwayat throttle login: {$expiredLogins}\n";

if (!$apply) {
    echo "Tidak ada perubahan. Tambahkan --apply untuk menjalankan.\n";
    exit(0);
}

foreach ($orders as $order) {
    $app->db()->transaction(function ($db) use ($order): void {
        $db->execute(
            "UPDATE orders SET customer_name = 'Pelanggan dihapus', phone = '', address = NULL, notes = NULL WHERE id = ?",
            [(int) $order['id']]
        );
        $db->execute(
            'INSERT INTO audit_logs (seller_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?)',
            [(int) $order['seller_id'], 'privacy.customer_anonymized', 'order', (int) $order['id'], null]
        );
    });
}
$app->db()->execute('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 30 DAY');
$app->db()->execute('DELETE FROM login_attempts WHERE updated_at < NOW() - INTERVAL 30 DAY');
echo "Selesai.\n";