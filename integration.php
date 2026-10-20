<?php

declare(strict_types=1);

/**
 * Integration test opsional.
 * Jalankan: RUN_INTEGRATION=1 php tests/integration.php
 */

$app = require dirname(__DIR__) . '/src/bootstrap.php';

if (getenv('RUN_INTEGRATION') !== '1') {
    echo "SKIP: set RUN_INTEGRATION=1 to run MySQL integration tests.\n";
    exit(0);
}

function integrationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

if (!extension_loaded('pdo_mysql')) {
    echo "SKIP: ekstensi pdo_mysql tidak terpasang.\n";
    exit(0);
}

$db = $app->db();
try {
    $db->value('SELECT 1');
} catch (PDOException $exception) {
    echo "SKIP: database tidak dapat dihubungi ({$exception->getMessage()}).\n";
    exit(0);
}
$suffix = strtoupper(bin2hex(random_bytes(4)));
$email = 'integration-' . strtolower($suffix) . '@example.test';
$slug = 'integration-' . strtolower($suffix);
$orderId = 0;
$userId = 0;
$sellerId = 0;
$otherSellerId = 0;
$categoryId = 0;
$menuId = 0;

try {
    $db->transaction(function ($database) use ($email, $slug, &$userId, &$sellerId, &$otherSellerId, &$categoryId, &$menuId): void {
        $database->execute('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)', [$email, password_hash('IntegrationKuat123', PASSWORD_DEFAULT), 'Integration Seller']);
        $userId = $database->lastInsertId();
        $database->execute('INSERT INTO sellers (name, slug, shipping_fee, tax_rate) VALUES (?, ?, ?, ?)', ['Integration Store', $slug, 7000, 10]);
        $sellerId = $database->lastInsertId();
        $database->execute('INSERT INTO seller_users (seller_id, user_id, role) VALUES (?, ?, ?)', [$sellerId, $userId, 'OWNER']);
        $database->execute('INSERT INTO sellers (name, slug, shipping_fee, tax_rate) VALUES (?, ?, ?, ?)', ['Other Store', $slug . '-other', 0, 10]);
        $otherSellerId = $database->lastInsertId();
        $database->execute('INSERT INTO categories (seller_id, name, category_type) VALUES (?, ?, ?)', [$sellerId, 'Makanan', 'MAKANAN']);
        $categoryId = $database->lastInsertId();
        $database->execute('INSERT INTO menu_items (seller_id, category_id, name, price, is_available) VALUES (?, ?, ?, ?, 1)', [$sellerId, $categoryId, 'Nasi Goreng', 18000]);
        $menuId = $database->lastInsertId();
    });

    $_SESSION = [];
    $cart = new PesanMenu\Services\CartService();
    $pricing = new PesanMenu\Services\PricingService();
    $orders = new PesanMenu\Services\OrderService($db, $pricing, $cart, 30, false);
    $cart->add((int) $sellerId, (int) $menuId, 2);
    $key = hash('sha256', 'integration-' . $suffix);

    $created = $orders->createFromCart(
        (int) $sellerId,
        ['name' => 'Budi Test', 'phone' => '08123456789', 'address' => 'Jl. Test No. 1'],
        'DELIVERY',
        'Tanpa sambal',
        'COD',
        $key
    );
    $orderId = (int) $created['order_id'];
    integrationExpect($orderId > 0, 'order COD harus dibuat');
    integrationExpect($created['order_number'] !== '', 'nomor order harus tersedia');

    $order = $db->one('SELECT * FROM orders WHERE id = ? AND seller_id = ?', [$orderId, $sellerId]);
    integrationExpect($order !== null, 'order harus terisolasi ke seller');
    integrationExpect((int) $order['total'] === 46600, 'total COD harus 46600');
    integrationExpect($order['status'] === 'PENDING_CONFIRMATION', 'status awal COD harus menunggu konfirmasi');

    $items = $orders->orderItems($orderId, (int) $sellerId);
    integrationExpect(count($items) === 1 && (int) $items[0]['quantity'] === 2, 'snapshot item harus tersimpan');

    $duplicate = $orders->createFromCart(
        (int) $sellerId,
        ['name' => 'Budi Test', 'phone' => '08123456789', 'address' => 'Jl. Test No. 1'],
        'DELIVERY',
        'Tanpa sambal',
        'COD',
        $key
    );
    integrationExpect((int) $duplicate['order_id'] === $orderId, 'idempotency harus mencegah order duplikat');

    $crossTenant = $db->one('SELECT id FROM orders WHERE id = ? AND seller_id = ?', [$orderId, $otherSellerId]);
    integrationExpect($crossTenant === null, 'seller lain tidak boleh melihat order');

    $paid = $orders->markCodPaid((int) $sellerId, $orderId, $userId);
    integrationExpect($paid, 'COD harus dapat ditandai paid');
    $paidOrder = $db->one('SELECT payment_status FROM orders WHERE id = ?', [$orderId]);
    integrationExpect($paidOrder['payment_status'] === 'PAID', 'payment status harus PAID');

    $cart->clear((int) $sellerId);
    $cart->add((int) $sellerId, (int) $menuId, 1);
    $qrisRejected = false;
    try {
        $orders->createFromCart((int) $sellerId, ['name' => 'Budi', 'phone' => '08123456789', 'address' => 'Jl. Test'], 'PICKUP', null, 'QRIS', hash('sha256', 'qris-' . $suffix));
    } catch (InvalidArgumentException) {
        $qrisRejected = true;
    }
    integrationExpect($qrisRejected, 'QRIS harus ditolak ketika adapter nonaktif');

    echo "OK: MySQL integration tests passed.\n";
} finally {
    if ($orderId > 0) {
        $db->execute('DELETE FROM orders WHERE id = ?', [$orderId]);
    }
    if ($sellerId > 0) {
        $db->execute('DELETE FROM menu_items WHERE seller_id = ?', [$sellerId]);
        $db->execute('DELETE FROM categories WHERE seller_id = ?', [$sellerId]);
        $db->execute('DELETE FROM seller_users WHERE seller_id = ?', [$sellerId]);
        $db->execute('DELETE FROM sellers WHERE id = ?', [$sellerId]);
    }
    if ($otherSellerId > 0) {
        $db->execute('DELETE FROM sellers WHERE id = ?', [$otherSellerId]);
    }
    if ($userId > 0) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
}