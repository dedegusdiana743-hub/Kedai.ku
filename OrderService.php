<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use InvalidArgumentException;
use PDOException;
use PesanMenu\Core\Database;

final class OrderService
{
    public function __construct(
        private readonly Database $db,
        private readonly PricingService $pricing,
        private readonly CartService $cart,
        private readonly int $expiryMinutes = 30,
        private readonly bool $qrisEnabled = false
    ) {
    }

    public function sellerBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM sellers WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug]);
    }

    public function sellerById(int $sellerId): ?array
    {
        return $this->db->one('SELECT * FROM sellers WHERE id = ? AND is_active = 1 LIMIT 1', [$sellerId]);
    }

    public function findByTrackingToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->db->one(
            'SELECT o.*, s.name AS seller_name, s.slug AS seller_slug, s.whatsapp
             FROM orders o JOIN sellers s ON s.id = o.seller_id
             WHERE o.tracking_token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
    }

    /**
     * @return array{order_id:int,order_number:string,tracking_token:string,existing:bool}
     */
    public function createFromCart(
        int $sellerId,
        array $customer,
        string $deliveryType,
        ?string $notes,
        string $paymentMethod,
        string $idempotencyKey
    ): array {
        if (!in_array($deliveryType, ['PICKUP', 'DELIVERY'], true)) {
            throw new InvalidArgumentException('Metode pengiriman tidak valid.');
        }
        if ($paymentMethod !== 'COD' && !($this->qrisEnabled && $paymentMethod === 'QRIS')) {
            throw new InvalidArgumentException('Metode pembayaran belum tersedia.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Idempotency key tidak valid.');
        }
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerPhone = trim((string) ($customer['phone'] ?? ''));
        $customerAddress = trim((string) ($customer['address'] ?? ''));
        if (mb_strlen($customerName) < 2 || mb_strlen($customerName) > 120) {
            throw new InvalidArgumentException('Data nama pelanggan tidak valid.');
        }
        if (!preg_match('/^[0-9+() .-]{8,30}$/', $customerPhone)) {
            throw new InvalidArgumentException('Nomor WhatsApp tidak valid.');
        }
        if ($deliveryType === 'DELIVERY' && (mb_strlen($customerAddress) < 8 || mb_strlen($customerAddress) > 500)) {
            throw new InvalidArgumentException('Alamat pengiriman tidak valid.');
        }
        if ($notes !== null && mb_strlen($notes) > 500) {
            throw new InvalidArgumentException('Catatan terlalu panjang.');
        }

        $existing = $this->db->one(
            'SELECT id, order_number FROM orders WHERE seller_id = ? AND idempotency_key = ? LIMIT 1',
            [$sellerId, $idempotencyKey]
        );
        if ($existing) {
            return [
                'order_id' => (int) $existing['id'],
                'order_number' => (string) $existing['order_number'],
                'tracking_token' => (string) ($_SESSION['order_tokens'][$idempotencyKey] ?? ''),
                'existing' => true,
            ];
        }

        $cart = $this->cart->all($sellerId);
        if (!$cart) {
            throw new InvalidArgumentException('Keranjang tidak valid.');
        }

        try {
            return $this->db->transaction(function (Database $db) use (
                $sellerId,
                $cart,
                $customer,
                $deliveryType,
                $notes,
                $paymentMethod,
                $idempotencyKey
            ): array {
                $seller = $db->one('SELECT * FROM sellers WHERE id = ? AND is_active = 1 FOR UPDATE', [$sellerId]);
                if (!$seller) {
                    throw new InvalidArgumentException('Toko tidak valid atau sedang nonaktif.');
                }

                $menuIds = array_keys($cart);
                $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
                $rows = $db->all(
                    "SELECT mi.*, c.name AS category_name
                     FROM menu_items mi
                     JOIN categories c ON c.id = mi.category_id AND c.seller_id = mi.seller_id
                     WHERE mi.seller_id = ? AND mi.id IN ($placeholders) AND mi.deleted_at IS NULL
                     FOR UPDATE",
                    array_merge([$sellerId], $menuIds)
                );
                $byId = [];
                foreach ($rows as $row) {
                    $byId[(int) $row['id']] = $row;
                }
                if (count($byId) !== count($cart)) {
                    throw new InvalidArgumentException('Salah satu menu sudah tidak tersedia.');
                }

                $items = [];
                foreach ($cart as $menuId => $quantity) {
                    $menuId = (int) $menuId;
                    $row = $byId[$menuId] ?? null;
                    if (!$row || !(bool) $row['is_available']) {
                        throw new InvalidArgumentException('Menu sedang tidak tersedia.');
                    }
                    $items[] = [
                        'menu_item_id' => $menuId,
                        'name_snapshot' => (string) $row['name'],
                        'unit_price' => (int) $row['price'],
                        'quantity' => (int) $quantity,
                        'line_total' => (int) $row['price'] * (int) $quantity,
                    ];
                }

                $totals = $this->pricing->calculate($items, $seller, $deliveryType);
                $trackingToken = bin2hex(random_bytes(32));
                $orderNumber = $this->newOrderNumber();
                $status = $paymentMethod === 'QRIS' ? 'PENDING_PAYMENT' : 'PENDING_CONFIRMATION';
                $expiresAt = $paymentMethod === 'QRIS'
                    ? date('Y-m-d H:i:s', time() + ($this->expiryMinutes * 60))
                    : null;
                $db->execute(
                    'INSERT INTO orders
                     (order_number, seller_id, customer_name, phone, delivery_type, address, notes, subtotal, shipping_fee, tax_rate, tax_amount, total, payment_method, payment_status, status, tracking_token_hash, idempotency_key, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $orderNumber,
                        $sellerId,
                        (string) $customer['name'],
                        (string) $customer['phone'],
                        $deliveryType,
                        $deliveryType === 'DELIVERY' ? (string) $customer['address'] : null,
                        $notes !== null ? mb_substr(trim($notes), 0, 500) : null,
                        $totals['subtotal'],
                        $totals['shipping_fee'],
                        $totals['tax_rate'],
                        $totals['tax_amount'],
                        $totals['total'],
                        $paymentMethod,
                        'PENDING',
                        $status,
                        hash('sha256', $trackingToken),
                        $idempotencyKey,
                        $expiresAt,
                    ]
                );
                $orderId = $db->lastInsertId();

                foreach ($items as $item) {
                    $db->execute(
                        'INSERT INTO order_items
                         (order_id, seller_id, menu_item_id, name_snapshot, unit_price, quantity, line_total)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [
                            $orderId,
                            $sellerId,
                            $item['menu_item_id'],
                            $item['name_snapshot'],
                            $item['unit_price'],
                            $item['quantity'],
                            $item['line_total'],
                        ]
                    );
                }

                $db->execute(
                    'INSERT INTO payments (order_id, method, status, amount, provider) VALUES (?, ?, ?, ?, ?)',
                    [$orderId, $paymentMethod, 'PENDING', $totals['total'], $paymentMethod === 'QRIS' ? 'midtrans' : null]
                );
                $db->execute(
                    'INSERT INTO order_events (order_id, from_status, to_status, note) VALUES (?, NULL, ?, ?)',
                    [$orderId, $status, 'Pesanan dibuat oleh pelanggan']
                );
                $db->execute(
                    'INSERT INTO audit_logs (seller_id, action, entity_type, entity_id, metadata) VALUES (?, ?, ?, ?, ?)',
                    [$sellerId, 'order.created', 'order', $orderId, json_encode(['source' => 'web'], JSON_THROW_ON_ERROR)]
                );

                $_SESSION['order_tokens'][$idempotencyKey] = $trackingToken;
                if (count($_SESSION['order_tokens']) > 20) {
                    $_SESSION['order_tokens'] = array_slice($_SESSION['order_tokens'], -20, null, true);
                }
                return [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'tracking_token' => $trackingToken,
                    'existing' => false,
                ];
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existing = $this->db->one(
                    'SELECT id, order_number FROM orders WHERE seller_id = ? AND idempotency_key = ? LIMIT 1',
                    [$sellerId, $idempotencyKey]
                );
                if ($existing) {
                    return [
                        'order_id' => (int) $existing['id'],
                        'order_number' => (string) $existing['order_number'],
                        'tracking_token' => (string) ($_SESSION['order_tokens'][$idempotencyKey] ?? ''),
                        'existing' => true,
                    ];
                }
            }
            throw $exception;
        }
    }

    public function updateStatus(int $sellerId, int $orderId, string $target, ?int $actorUserId, ?string $note = null): bool
    {
        return $this->db->transaction(function (Database $db) use ($sellerId, $orderId, $target, $actorUserId, $note): bool {
            $order = $db->one('SELECT * FROM orders WHERE id = ? AND seller_id = ? FOR UPDATE', [$orderId, $sellerId]);
            if (!$order || !self::canTransition((string) $order['status'], $target)) {
                return false;
            }
            if (
                ($order['delivery_type'] === 'DELIVERY' && $target === 'READY_FOR_PICKUP') ||
                ($order['delivery_type'] === 'PICKUP' && $target === 'READY_FOR_DELIVERY')
            ) {
                return false;
            }
            if ($target === 'CANCELLED' && $order['payment_status'] === 'PAID') {
                return false;
            }

            $db->execute(
                'UPDATE orders SET status = ?, version = version + 1 WHERE id = ? AND seller_id = ?',
                [$target, $orderId, $sellerId]
            );
            $db->execute(
                'INSERT INTO order_events (order_id, from_status, to_status, actor_user_id, note) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $order['status'], $target, $actorUserId, $note !== null ? mb_substr($note, 0, 255) : null]
            );
            $db->execute(
                'INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$sellerId, $actorUserId, 'order.status_changed', 'order', $orderId, json_encode(['from' => $order['status'], 'to' => $target], JSON_THROW_ON_ERROR)]
            );
            return true;
        });
    }

    public function markCodPaid(int $sellerId, int $orderId, int $actorUserId): bool
    {
        return $this->db->transaction(function (Database $db) use ($sellerId, $orderId, $actorUserId): bool {
            $order = $db->one('SELECT * FROM orders WHERE id = ? AND seller_id = ? FOR UPDATE', [$orderId, $sellerId]);
            if (!$order || $order['payment_method'] !== 'COD' || $order['payment_status'] === 'PAID' || in_array($order['status'], ['CANCELLED', 'REJECTED', 'EXPIRED'], true)) {
                return false;
            }
            $db->execute('UPDATE payments SET status = ?, paid_at = NOW() WHERE order_id = ? AND method = ?', ['PAID', $orderId, 'COD']);
            $db->execute('UPDATE orders SET payment_status = ?, version = version + 1 WHERE id = ? AND seller_id = ?', ['PAID', $orderId, $sellerId]);
            $db->execute('INSERT INTO order_events (order_id, from_status, to_status, actor_user_id, note) VALUES (?, ?, ?, ?, ?)', [$orderId, $order['status'], $order['status'], $actorUserId, 'COD dikonfirmasi sudah dibayar']);
            $db->execute('INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)', [$sellerId, $actorUserId, 'payment.cod_paid', 'order', $orderId]);
            return true;
        });
    }

    public function orderItems(int $orderId, int $sellerId): array
    {
        return $this->db->all('SELECT * FROM order_items WHERE order_id = ? AND seller_id = ? ORDER BY id', [$orderId, $sellerId]);
    }

    public function events(int $orderId): array
    {
        return $this->db->all(
            'SELECT e.*, u.name AS actor_name FROM order_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.order_id = ? ORDER BY e.created_at, e.id',
            [$orderId]
        );
    }

    public static function canTransition(string $from, string $to): bool
    {
        $allowed = [
            'PENDING_PAYMENT' => ['PENDING_CONFIRMATION', 'CANCELLED', 'EXPIRED'],
            'PENDING_CONFIRMATION' => ['ACCEPTED', 'REJECTED', 'CANCELLED', 'EXPIRED'],
            'ACCEPTED' => ['PREPARING', 'CANCELLED'],
            'PREPARING' => ['READY_FOR_PICKUP', 'READY_FOR_DELIVERY', 'CANCELLED'],
            'READY_FOR_PICKUP' => ['COMPLETED'],
            'READY_FOR_DELIVERY' => ['OUT_FOR_DELIVERY', 'COMPLETED'],
            'OUT_FOR_DELIVERY' => ['COMPLETED'],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    public function expirePendingOrders(): int
    {
        $ids = $this->db->all(
            "SELECT id FROM orders WHERE status = 'PENDING_PAYMENT' AND expires_at < NOW()"
        );
        $count = 0;
        foreach ($ids as $row) {
            $orderId = (int) $row['id'];
            $updated = $this->db->transaction(function (Database $db) use ($orderId): bool {
                $order = $db->one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
                if (!$order || $order['status'] !== 'PENDING_PAYMENT' || !$order['expires_at'] || $order['expires_at'] >= date('Y-m-d H:i:s')) {
                    return false;
                }
                $db->execute("UPDATE orders SET status = 'EXPIRED', payment_status = 'EXPIRED', version = version + 1 WHERE id = ?", [$orderId]);
                $db->execute("UPDATE payments SET status = 'EXPIRED' WHERE order_id = ? AND status = 'PENDING'", [$orderId]);
                $db->execute("UPDATE payment_attempts SET status = 'EXPIRED' WHERE payment_id = (SELECT id FROM payments WHERE order_id = ?) AND status = 'PENDING'", [$orderId]);
                $db->execute('INSERT INTO order_events (order_id, from_status, to_status, note) VALUES (?, ?, ?, ?)', [$orderId, $order['status'], 'EXPIRED', 'Kedaluwarsa otomatis']);
                return true;
            });
            if ($updated) {
                $count++;
            }
        }

        return $count;
    }

    private function newOrderNumber(): string
    {
        return 'PM' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
    }
}
