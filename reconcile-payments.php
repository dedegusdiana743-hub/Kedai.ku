<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';
if (!$app->paymentsEnabled()) {
    fwrite(STDERR, "QRIS belum dikonfigurasi.\n");
    exit(1);
}

$service = $app->paymentService();
$payments = $service->reconcilePending(100);
$refunds = $service->reconcileRefunds(100);
echo "{$payments} transaksi QRIS dan {$refunds} refund direkonsiliasi.\n";