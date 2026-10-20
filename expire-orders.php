<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$service = new PesanMenu\Services\OrderService(
    $app->db(),
    new PesanMenu\Services\PricingService(),
    new PesanMenu\Services\CartService(),
    (int) $app->config('app.order_expiry_minutes', 30),
    $app->paymentsEnabled()
);
$count = $service->expirePendingOrders();
echo "{$count} pesanan kedaluwarsa.\n";
