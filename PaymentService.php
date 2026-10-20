<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use PesanMenu\Contracts\PaymentGatewayInterface;
use PesanMenu\Core\Database;
use PesanMenu\Exceptions\PaymentException;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly Database $db,
        private readonly PaymentGatewayInterface $gateway,
        private readonly int $expiryMinutes = 30
    ) {
    }

    /**
     * @return array{qr_url:string, transaction_id:string, expires_at:string|null}
     */
    public function createQrisCharge(int $orderId): array
    {
        $state = $this->db->transaction(function (Database $db) use ($orderId): array {
            $order = $db->one(
                "SELECT o.*, p.id AS payment_id, p.status AS payment_record_status, p.provider_order_id,
                        p.provider_reference, p.qr_url, p.expiry_at AS payment_expiry_at
                 FROM orders o JOIN payments p ON p.order_id = o.id
                 WHERE o.id = ? AND p.method = 'QRIS' LIMIT 1 FOR UPDATE",
                [$orderId]
            );
            if (!$order || !in_array($order['status'], ['PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'EXPIRED'], true)) {
                throw new PaymentException('Pesanan tidak dapat membuat pembayaran QRIS.', 422, 'INVALID_ORDER');
            }
            if ($order['payment_record_status'] === 'PENDING' && !empty($order['qr_url']) && !empty($order['provider_reference'])) {
                return ['reuse' => true, 'order' => $order];
            }
            if (in_array($order['payment_record_status'], ['PAID', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                throw new PaymentException('Pembayaran QRIS sudah berhasil diproses.', 422, 'ALREADY_PAID');
            }
            $recentAttempts = (int) $db->value(
                'SELECT COUNT(*) FROM payment_attempts WHERE payment_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
                [(int) $order['payment_id']]
            );
            if ($recentAttempts >= 3) {
                throw new PaymentException('Terlalu banyak percobaan membuat QRIS. Coba lagi beberapa menit.', 429, 'RATE_LIMITED');
            }
            $providerOrderId = (string) $order['order_number'] . '-' . strtoupper(bin2hex(random_bytes(4)));
            $db->execute(
                'INSERT INTO payment_attempts (payment_id, provider, provider_order_id, status, amount) VALUES (?, ?, ?, ?, ?)',
                [(int) $order['payment_id'], 'midtrans', $providerOrderId, 'PENDING', (int) $order['total']]
            );
            return ['reuse' => false, 'order' => $order, 'provider_order_id' => $providerOrderId, 'attempt_id' => $db->lastInsertId()];
        });
        $order = $state['order'];
        if ($state['reuse']) {
            return [
                'qr_url' => (string) $order['qr_url'],
                'transaction_id' => (string) $order['provider_reference'],
                'expires_at' => (string) ($order['payment_expiry_at'] ?? ''),
            ];
        }
        $providerOrderId = (string) $state['provider_order_id'];
        $attemptId = (int) $state['attempt_id'];
        $order['provider_order_id'] = $providerOrderId;

        $items = $this->db->all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        if (!$items) {
            throw new PaymentException('Item pesanan tidak ditemukan.', 422, 'EMPTY_ORDER');
        }

        try {
            $charge = $this->gateway->createCharge($order, $items, [
                'name' => (string) $order['customer_name'],
                'phone' => (string) $order['phone'],
            ]);
            $expiresAt = date('Y-m-d H:i:s', time() + ($this->expiryMinutes * 60));
            $this->db->transaction(function (Database $db) use ($order, $orderId, $providerOrderId, $charge, $expiresAt, $attemptId): void {
                $db->execute(
                    "UPDATE payments SET provider = 'midtrans', provider_order_id = ?, provider_reference = ?, qr_url = ?, expiry_at = ?, failure_reason = NULL, status = 'PENDING' WHERE order_id = ? AND method = 'QRIS'",
                    [$providerOrderId, $charge['transaction_id'], $charge['qr_url'], $expiresAt, $orderId]
                );
                $db->execute(
                    'UPDATE payment_attempts SET provider_reference = ?, qr_url = ?, expiry_at = ? WHERE id = ?',
                    [$charge['transaction_id'], $charge['qr_url'], $expiresAt, $attemptId]
                );
                if ($order['status'] === 'EXPIRED') {
                    $db->execute("UPDATE orders SET status = 'PENDING_PAYMENT', payment_status = 'PENDING', expires_at = ?, version = version + 1 WHERE id = ?", [$expiresAt, $orderId]);
                    $db->execute('INSERT INTO order_events (order_id, from_status, to_status, note) VALUES (?, ?, ?, ?)', [$orderId, 'EXPIRED', 'PENDING_PAYMENT', 'QRIS dibuat ulang']);
                } elseif ($order['payment_status'] !== 'PENDING') {
                    $db->execute("UPDATE orders SET payment_status = 'PENDING', expires_at = ?, version = version + 1 WHERE id = ?", [$expiresAt, $orderId]);
                }
            });
            $this->db->execute(
                'INSERT INTO audit_logs (seller_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?)',
                [(int) $order['seller_id'], 'payment.qris_created', 'order', $orderId, json_encode(['transaction_id' => $charge['transaction_id']], JSON_THROW_ON_ERROR)]
            );

            return [
                'qr_url' => $charge['qr_url'],
                'transaction_id' => $charge['transaction_id'],
                'expires_at' => $expiresAt,
            ];
        } catch (PaymentException $exception) {
            if ($exception->httpStatus >= 400 && $exception->httpStatus < 500) {
                $this->db->execute("UPDATE payment_attempts SET status = 'FAILED' WHERE id = ?", [$attemptId]);
            }
            $this->db->execute(
                "UPDATE payments SET failure_reason = ? WHERE order_id = ? AND method = 'QRIS'",
                [mb_substr($exception->getMessage(), 0, 255), $orderId]
            );
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->execute(
                "UPDATE payments SET failure_reason = ? WHERE order_id = ? AND method = 'QRIS'",
                ['Kesalahan internal saat membuat pembayaran.', $orderId]
            );
            throw new PaymentException('Pembayaran QRIS belum dapat dibuat. Coba lagi.', 502, 'INTERNAL');
        }
    }

    /**
     * @return array{ok:bool, duplicate:bool, status:string}
     */
    public function applyNotification(array $payload, bool $fromReconciliation = false): array
    {
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureValid = $fromReconciliation ? true : $this->gateway->verifyNotification($payload);
        $eventKey = $this->eventKey($payload, $fromReconciliation);
        $this->db->execute(
            'INSERT IGNORE INTO webhook_events (provider, event_key, payload_hash, signature_valid, status) VALUES (?, ?, ?, ?, ?)',
            ['midtrans', $eventKey, $payloadHash, $signatureValid ? 1 : 0, $signatureValid ? 'RECEIVED' : 'REJECTED']
        );
        $event = $this->db->one('SELECT * FROM webhook_events WHERE provider = ? AND event_key = ? LIMIT 1', ['midtrans', $eventKey]);
        if (!$signatureValid) {
            $this->db->execute("UPDATE webhook_events SET status = 'REJECTED', error_message = ?, processed_at = NOW() WHERE provider = ? AND event_key = ?", ['Signature tidak valid.', 'midtrans', $eventKey]);
            throw new PaymentException('Signature notification tidak valid.', 403, 'INVALID_SIGNATURE');
        }
        if ($event && $event['status'] === 'PROCESSED') {
            return ['ok' => true, 'duplicate' => true, 'status' => (string) ($payload['transaction_status'] ?? '')];
        }

        try {
            $status = $this->applyStatus($payload);
            $this->db->execute("UPDATE webhook_events SET status = 'PROCESSED', processed_at = NOW(), error_message = NULL WHERE provider = ? AND event_key = ?", ['midtrans', $eventKey]);
            return ['ok' => true, 'duplicate' => false, 'status' => $status];
        } catch (Throwable $exception) {
            $this->db->execute("UPDATE webhook_events SET status = 'FAILED', error_message = ?, processed_at = NOW() WHERE provider = ? AND event_key = ?", [mb_substr($exception->getMessage(), 0, 500), 'midtrans', $eventKey]);
            throw $exception;
        }
    }

    public function reconcilePending(int $limit = 50): int
    {
        $rows = $this->db->all(
            "SELECT o.order_number, pa.provider_order_id FROM payment_attempts pa
             JOIN payments p ON p.id = pa.payment_id JOIN orders o ON o.id = p.order_id
             WHERE p.method = 'QRIS' AND pa.status = 'PENDING' AND pa.created_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             ORDER BY o.created_at ASC LIMIT " . max(1, min(200, $limit))
        );
        $processed = 0;
        foreach ($rows as $row) {
            try {
                $payload = $this->gateway->status((string) $row['provider_order_id']);
                $this->applyNotification($payload, true);
                $processed++;
            } catch (Throwable $exception) {
                $this->db->execute(
                    'INSERT INTO audit_logs (action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?)',
                    ['payment.reconcile_failed', 'order', null, json_encode(['order_id' => $row['order_number'], 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR)]
                );
            }
        }

        return $processed;
    }

    public function reconcileRefunds(int $limit = 50): int
    {
        $rows = $this->db->all(
            "SELECT r.*, p.provider_order_id, o.total AS order_total
             FROM refunds r JOIN payments p ON p.id = r.payment_id JOIN orders o ON o.id = r.order_id
             WHERE r.status = 'PENDING' AND r.created_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY r.created_at ASC LIMIT " . max(1, min(200, $limit))
        );
        $resolved = 0;
        foreach ($rows as $row) {
            if ((int) $row['provider_called'] === 0) {
                $this->db->execute("UPDATE refunds SET status = 'FAILED', failure_reason = ? WHERE id = ?", ['Proses berhenti sebelum permintaan dikirim ke provider.', (int) $row['id']]);
                $resolved++;
                continue;
            }
            try {
                $status = $this->gateway->status((string) $row['provider_order_id']);
                $createdAt = strtotime((string) $row['created_at']);
                $matched = false;
                $matchedId = null;
                foreach (($status['refunds'] ?? []) as $providerRefund) {
                    $refundId = (string) ($providerRefund['refund_chargeback_id'] ?? '');
                    $refundTime = strtotime((string) ($providerRefund['created_at'] ?? $providerRefund['refund_time'] ?? '')) ?: 0;
                    $sameAmount = (float) ($providerRefund['refund_amount'] ?? 0) === (float) $row['amount'];
                    if ($refundId !== '' && $row['provider_refund_id'] !== null && $refundId === (string) $row['provider_refund_id']) {
                        $matched = true;
                        $matchedId = $refundId;
                        break;
                    }
                    if ($sameAmount && $refundTime >= ($createdAt - 60) && $refundTime <= (time() + 300)) {
                        $matched = true;
                        $matchedId = $refundId !== '' ? $refundId : null;
                        break;
                    }
                }
                if ($matched) {
                    $this->completeRefund((int) $row['id'], $matchedId);
                    $resolved++;
                    continue;
                }
                $ageHours = (time() - $createdAt) / 3600;
                if ($ageHours >= 24) {
                    $this->db->execute("UPDATE refunds SET failure_reason = ? WHERE id = ? AND status = 'PENDING'", ['Belum terkonfirmasi di provider setelah 24 jam; periksa settlement manual sebelum refund ulang.', (int) $row['id']]);
                }
            } catch (Throwable $exception) {
                $this->db->execute(
                    'INSERT INTO audit_logs (action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?)',
                    ['refund.reconcile_failed', 'refund', (int) $row['id'], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)]
                );
            }
        }

        return $resolved;
    }

    public function refund(int $sellerId, int $orderId, int $amount, string $reason, int $actorUserId): bool
    {
        [$order, $remaining, $refundId] = $this->db->transaction(function (Database $db) use ($sellerId, $orderId, $amount, $reason, $actorUserId): array {
            $order = $db->one(
                "SELECT o.*, p.id AS payment_id, p.status AS payment_record_status, p.amount AS payment_amount, p.provider_order_id
                 FROM orders o JOIN payments p ON p.order_id = o.id
                 WHERE o.id = ? AND o.seller_id = ? FOR UPDATE",
                [$orderId, $sellerId]
            );
            if (!$order || $order['payment_method'] !== 'QRIS' || !in_array($order['payment_record_status'], ['PAID', 'PARTIALLY_REFUNDED'], true) || empty($order['provider_order_id'])) {
                throw new PaymentException('Refund hanya dapat dilakukan untuk QRIS yang sudah dibayar.', 422, 'INVALID_REFUND');
            }
            $pendingRefunds = (int) $db->value("SELECT COUNT(*) FROM refunds WHERE order_id = ? AND status = 'PENDING'", [$orderId]);
            if ($pendingRefunds > 0) {
                throw new PaymentException('Ada refund yang sedang menunggu konfirmasi provider. Tidak ada refund baru sampai statusnya jelas.', 409, 'REFUND_PENDING');
            }
            $refunded = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ? AND status = 'SUCCEEDED'", [$orderId]);
            $remaining = (int) $order['total'] - $refunded;
            if ($amount < 1 || $amount > $remaining) {
                throw new PaymentException('Nominal refund melebihi sisa yang dapat direfund.', 422, 'INVALID_AMOUNT');
            }
            $db->execute(
                'INSERT INTO refunds (order_id, payment_id, amount, reason, status, requested_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$orderId, (int) $order['payment_id'], $amount, mb_substr($reason, 0, 255), 'PENDING', $actorUserId]
            );
            return [$order, $remaining, $db->lastInsertId()];
        });
        $this->db->execute('UPDATE refunds SET provider_called = 1 WHERE id = ?', [$refundId]);
        try {
            $response = $this->gateway->refund((string) $order['provider_order_id'], $amount, 'OTHER');
            if (isset($response['order_id']) && (string) $response['order_id'] !== (string) $order['provider_order_id']) {
                throw new PaymentException('Respons refund tidak sesuai dengan pesanan.', 502, 'REFUND_MISMATCH');
            }
            $responseAmount = $response['amount'] ?? $response['gross_amount'] ?? null;
            if ($responseAmount !== null && (float) $responseAmount !== (float) $amount) {
                throw new PaymentException('Nominal respons refund tidak sesuai.', 502, 'REFUND_AMOUNT_MISMATCH');
            }
            $providerId = (string) ($response['refund_id'] ?? $response['transaction_id'] ?? '');
            $this->completeRefund($refundId, $providerId);
            return true;
        } catch (Throwable $exception) {
            $definiteRejection = $exception instanceof PaymentException && $exception->httpStatus >= 400 && $exception->httpStatus < 500;
            $this->db->execute(
                'UPDATE refunds SET status = ?, failure_reason = ? WHERE id = ?',
                [$definiteRejection ? 'FAILED' : 'PENDING', mb_substr($exception->getMessage(), 0, 500), $refundId]
            );
            throw $exception;
        }
    }

    private function completeRefund(int $refundId, ?string $providerId): void
    {
        $this->db->transaction(function (Database $db) use ($refundId, $providerId): void {
            $refund = $db->one('SELECT * FROM refunds WHERE id = ? FOR UPDATE', [$refundId]);
            if (!$refund || $refund['status'] === 'SUCCEEDED') {
                return;
            }
            $payment = $db->one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [(int) $refund['payment_id']]);
            $order = $payment ? $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [(int) $payment['order_id']]) : null;
            if (!$payment || !$order) {
                throw new PaymentException('Data refund tidak lengkap.', 422, 'REFUND_DATA_MISSING');
            }
            $db->execute("UPDATE refunds SET status = 'SUCCEEDED', provider_refund_id = ?, failure_reason = NULL WHERE id = ?", [$providerId ?: $refund['provider_refund_id'], $refundId]);
            $refundedTotal = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ? AND status = 'SUCCEEDED'", [(int) $order['id']]);
            if ($refundedTotal >= (int) $order['total']) {
                $db->execute("UPDATE payments SET status = 'REFUNDED' WHERE id = ?", [(int) $payment['id']]);
                $db->execute("UPDATE orders SET payment_status = 'REFUNDED', version = version + 1 WHERE id = ?", [(int) $order['id']]);
                $db->execute("UPDATE payment_attempts SET status = 'REFUNDED' WHERE payment_id = ? AND provider_order_id = ?", [(int) $payment['id'], $payment['provider_order_id']]);
            } else {
                $db->execute("UPDATE payments SET status = 'PARTIALLY_REFUNDED' WHERE id = ?", [(int) $payment['id']]);
                $db->execute("UPDATE payment_attempts SET status = 'PARTIALLY_REFUNDED' WHERE payment_id = ? AND provider_order_id = ?", [(int) $payment['id'], $payment['provider_order_id']]);
            }
            $db->execute(
                'INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $order['seller_id'], $refund['requested_by'], 'payment.refunded', 'order', (int) $order['id'], json_encode(['amount' => (int) $refund['amount'], 'refund_id' => $refundId], JSON_THROW_ON_ERROR)]
            );
        });
    }

    private function applyStatus(array $payload): string
    {
        $orderNumber = (string) ($payload['order_id'] ?? '');
        $providerStatus = strtolower((string) ($payload['transaction_status'] ?? ''));
        $currency = strtoupper((string) ($payload['currency'] ?? 'IDR'));
        if ($orderNumber === '' || $providerStatus === '') {
            throw new PaymentException('Notification tidak lengkap.', 422, 'INCOMPLETE');
        }
        if (strtolower((string) ($payload['payment_type'] ?? '')) !== 'qris' || $currency !== 'IDR') {
            throw new PaymentException('Jenis pembayaran atau mata uang tidak sesuai.', 422, 'TYPE_MISMATCH');
        }
        $mapped = match ($providerStatus) {
            'settlement', 'capture' => 'PAID',
            'pending' => 'PENDING',
            'expire', 'expired' => 'EXPIRED',
            'deny', 'denied', 'cancel', 'failure', 'failed' => 'FAILED',
            default => throw new PaymentException('Status pembayaran belum dikenal.', 422, 'UNKNOWN_STATUS'),
        };

        return $this->db->transaction(function (Database $db) use ($orderNumber, $payload, $mapped, $providerStatus): string {
            $transactionId = (string) ($payload['transaction_id'] ?? '');
            $attempt = $db->one(
                'SELECT * FROM payment_attempts WHERE provider_order_id = ? OR provider_reference = ? LIMIT 1 FOR UPDATE',
                [$orderNumber, $transactionId]
            );
            $payment = $attempt ? $db->one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [(int) $attempt['payment_id']]) : null;
            $order = $payment ? $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [(int) $payment['order_id']]) : null;
            if (!$order || !$payment || !$attempt || $payment['method'] !== 'QRIS') {
                throw new PaymentException('Pesanan tidak ditemukan.', 404, 'ORDER_NOT_FOUND');
            }
            $gross = (float) ($payload['gross_amount'] ?? 0);
            if ($gross !== (float) $order['total'] || (int) $payment['amount'] !== (int) $order['total']) {
                throw new PaymentException('Nominal pembayaran tidak sesuai dengan pesanan.', 422, 'AMOUNT_MISMATCH');
            }
            if ($attempt['provider_reference'] && $transactionId !== '' && $attempt['provider_reference'] !== $transactionId) {
                throw new PaymentException('Referensi transaksi tidak sesuai.', 422, 'REFERENCE_MISMATCH');
            }
            $newerActive = (int) $db->value(
                "SELECT COUNT(*) FROM payment_attempts WHERE payment_id = ? AND id > ? AND status = 'PENDING'",
                [(int) $payment['id'], (int) $attempt['id']]
            );
            if ($newerActive > 0 && in_array($mapped, ['PENDING', 'EXPIRED', 'FAILED'], true)) {
                $db->execute('UPDATE payment_attempts SET status = ? WHERE id = ?', [$mapped, (int) $attempt['id']]);
                return $mapped;
            }
            if (in_array($payment['status'], ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
                if ($mapped === 'PAID') {
                    $db->execute('UPDATE payment_attempts SET status = ? WHERE id = ?', ['PAID', (int) $attempt['id']]);
                }
                return $payment['status'];
            }
            $db->execute(
                "UPDATE payments SET provider = 'midtrans', provider_order_id = ?, provider_reference = ? WHERE id = ?",
                [$attempt['provider_order_id'], $transactionId ?: $attempt['provider_reference'], (int) $payment['id']]
            );
            $db->execute('UPDATE payment_attempts SET status = ? WHERE id = ?', [$mapped, (int) $attempt['id']]);
            $paidAt = $mapped === 'PAID' ? ($payload['settlement_time'] ?? date('Y-m-d H:i:s')) : $payment['paid_at'];
            $db->execute('UPDATE payments SET status = ?, paid_at = ?, failure_reason = ? WHERE id = ?', [$mapped, $paidAt, $mapped === 'FAILED' ? 'Ditolak atau dibatalkan provider.' : null, (int) $payment['id']]);

            $orderStatus = (string) $order['status'];
            if ($mapped === 'PAID' && in_array($orderStatus, ['PENDING_PAYMENT', 'EXPIRED'], true)) {
                $orderStatus = 'PENDING_CONFIRMATION';
            } elseif ($mapped === 'EXPIRED' && $orderStatus === 'PENDING_PAYMENT') {
                $orderStatus = 'EXPIRED';
            }
            $orderPaymentStatus = match ($mapped) {
                'PAID' => 'PAID',
                'EXPIRED' => 'EXPIRED',
                'FAILED' => 'FAILED',
                default => $order['payment_status'],
            };
            if ($orderStatus !== $order['status'] || $orderPaymentStatus !== $order['payment_status']) {
                $db->execute('UPDATE orders SET status = ?, payment_status = ?, version = version + 1 WHERE id = ?', [$orderStatus, $orderPaymentStatus, (int) $order['id']]);
                $db->execute('INSERT INTO order_events (order_id, from_status, to_status, note) VALUES (?, ?, ?, ?)', [(int) $order['id'], $order['status'], $orderStatus, 'Status pembayaran QRIS: ' . $providerStatus]);
            }
            $db->execute('INSERT INTO audit_logs (seller_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?)', [(int) $order['seller_id'], 'payment.status_changed', 'order', (int) $order['id'], json_encode(['provider_status' => $providerStatus, 'mapped' => $mapped], JSON_THROW_ON_ERROR)]);

            return $mapped;
        });
    }

    private function eventKey(array $payload, bool $fromReconciliation): string
    {
        $prefix = $fromReconciliation ? 'reconcile:' : '';
        return $prefix . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}