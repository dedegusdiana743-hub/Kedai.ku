<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$pricing = new PesanMenu\Services\PricingService();
$totals = $pricing->calculate([
    ['price' => 18000, 'quantity' => 2],
    ['price' => 5000, 'quantity' => 1],
], ['shipping_fee' => 7000, 'tax_rate' => 10], 'DELIVERY');
expect($totals['subtotal'] === 41000, 'subtotal harus dihitung dari harga x jumlah');
expect($totals['shipping_fee'] === 7000, 'ongkir hanya untuk delivery');
expect($totals['tax_amount'] === 4100, 'pajak harus dibulatkan ke rupiah');
expect($totals['total'] === 52100, 'total harus sama dengan subtotal + ongkir + pajak');

$pickup = $pricing->calculate([['price' => 10000, 'quantity' => 1]], ['shipping_fee' => 7000, 'tax_rate' => 10], 'PICKUP');
expect($pickup['shipping_fee'] === 0, 'pickup tidak memakai ongkir antar');
expect(PesanMenu\Services\OrderService::canTransition('PENDING_CONFIRMATION', 'ACCEPTED'), 'transisi diterima harus valid');
expect(!PesanMenu\Services\OrderService::canTransition('COMPLETED', 'CANCELLED'), 'status final tidak boleh dibatalkan');
expect(!PesanMenu\Services\OrderService::canTransition('PENDING_CONFIRMATION', 'COMPLETED'), 'transisi lompat harus ditolak');

$weakRejected = false;
try {
    PesanMenu\Services\PasswordResetService::validatePassword('short');
} catch (InvalidArgumentException) {
    $weakRejected = true;
}
expect($weakRejected, 'password lemah harus ditolak');

$gateway = new PesanMenu\Services\MidtransClient('test-server-key', false, 'gopay', 5);
$notification = [
    'order_id' => 'PM-TEST-001',
    'status_code' => '200',
    'gross_amount' => '52100.00',
    'transaction_status' => 'settlement',
    'signature_key' => hash('sha512', 'PM-TEST-001' . '200' . '52100.00' . 'test-server-key'),
];
expect($gateway->verifyNotification($notification), 'signature Midtrans valid harus diterima');
$notification['gross_amount'] = '999.00';
expect(!$gateway->verifyNotification($notification), 'signature dengan nominal berbeda harus ditolak');

ob_start();
$app->render('errors/404', ['title' => 'Test 404']);
$html = (string) ob_get_clean();
expect(str_contains($html, '<!doctype html>'), 'layout harus merender wrapper HTML');
expect(str_contains($html, 'Test 404'), 'judul error harus dirender');

echo "OK: pricing, state transition, signature, and render tests passed.\n";
