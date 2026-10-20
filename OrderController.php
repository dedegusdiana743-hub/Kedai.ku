<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;
use PesanMenu\Exceptions\PaymentException;

final class OrderController extends Controller
{
    public function index(Request $request): void
    {
        $seller = $this->seller();
        $allowed = [
            'PENDING_CONFIRMATION', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP',
            'READY_FOR_DELIVERY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'REJECTED',
            'CANCELLED', 'EXPIRED', 'PENDING_PAYMENT',
        ];
        $status = (string) $request->query('status', '');
        $params = [(int) $seller['id']];
        $where = 'o.seller_id = ?';
        if (in_array($status, $allowed, true)) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        $orders = $this->app->db()->all(
            "SELECT o.*, p.status AS payment_status FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE $where ORDER BY o.created_at DESC LIMIT 100",
            $params
        );
        $this->app->render('seller/orders/index', [
            'title' => 'Pesanan masuk',
            'seller' => $seller,
            'orders' => $orders,
            'status' => $status,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $seller = $this->seller();
        $order = $this->app->db()->one('SELECT o.*, p.status AS payment_status, p.paid_at FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.id = ? AND o.seller_id = ? LIMIT 1', [(int) $id, (int) $seller['id']]);
        if (!$order) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Pesanan tidak ditemukan']);
            return;
        }
        $service = $this->orderService();
        $this->app->render('seller/orders/show', [
            'title' => 'Detail pesanan ' . $order['order_number'],
            'seller' => $seller,
            'order' => $order,
            'items' => $service->orderItems((int) $order['id'], (int) $seller['id']),
            'events' => $service->events((int) $order['id']),
            'refunds' => $order['payment_method'] === 'QRIS' ? $this->app->db()->all('SELECT * FROM refunds WHERE order_id = ? ORDER BY created_at DESC', [(int) $order['id']]) : [],
            'refundedTotal' => $order['payment_method'] === 'QRIS' ? (int) $this->app->db()->value("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ? AND status IN ('PENDING','SUCCEEDED')", [(int) $order['id']]) : 0,
            'nextStatuses' => $this->nextStatuses((string) $order['status'], (string) $order['delivery_type']),
        ]);
    }

    public function updateStatus(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER', 'CASHIER', 'COURIER');
        $seller = $this->seller();
        $target = (string) $request->post('status');
        $note = trim((string) $request->post('note'));
        if ($note !== '' && mb_strlen($note) > 255) {
            $this->flashError('Catatan status maksimal 255 karakter.');
            redirect('/penjual/pesanan/' . (int) $id);
        }
        if ($seller['role'] === 'COURIER' && !in_array($target, ['OUT_FOR_DELIVERY', 'COMPLETED'], true)) {
            $this->flashError('Kurir hanya dapat memperbarui status pengantaran.');
            redirect('/penjual/pesanan/' . (int) $id);
        }
        $updated = $this->orderService()->updateStatus((int) $seller['id'], (int) $id, $target, $this->app->auth()->id(), $note !== '' ? $note : null);
        if ($updated) {
            $this->flashSuccess('Status pesanan diperbarui.');
        } else {
            $this->flashError('Status tidak dapat diubah dari kondisi saat ini.');
        }
        redirect('/penjual/pesanan/' . (int) $id);
    }

    public function markPaid(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER', 'CASHIER');
        $seller = $this->seller();
        $updated = $this->orderService()->markCodPaid((int) $seller['id'], (int) $id, (int) $this->app->auth()->id());
        if ($updated) {
            $this->flashSuccess('Pembayaran COD ditandai sudah dibayar.');
        } else {
            $this->flashError('Pembayaran tidak dapat ditandai untuk pesanan ini.');
        }
        redirect('/penjual/pesanan/' . (int) $id);
    }

    public function refund(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $amountRaw = trim((string) $request->post('amount'));
        $reason = trim((string) $request->post('reason'));
        if (!preg_match('/^\d{1,15}$/', $amountRaw) || mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            $this->flashError('Nominal atau alasan refund tidak valid.');
            redirect('/penjual/pesanan/' . (int) $id);
        }
        try {
            $this->app->paymentService()->refund((int) $seller['id'], (int) $id, (int) $amountRaw, $reason, (int) $this->app->auth()->id());
            $this->flashSuccess('Refund berhasil diproses.');
        } catch (PaymentException $exception) {
            $this->app->log($exception);
            $this->flashError($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->app->log($exception);
            $this->flashError('Refund belum berhasil diproses.');
        }
        redirect('/penjual/pesanan/' . (int) $id);
    }

    private function nextStatuses(string $status, string $deliveryType): array
    {
        $map = [
            'PENDING_CONFIRMATION' => ['ACCEPTED', 'REJECTED', 'CANCELLED'],
            'ACCEPTED' => ['PREPARING', 'CANCELLED'],
            'PREPARING' => [$deliveryType === 'DELIVERY' ? 'READY_FOR_DELIVERY' : 'READY_FOR_PICKUP', 'CANCELLED'],
            'READY_FOR_PICKUP' => ['COMPLETED'],
            'READY_FOR_DELIVERY' => ['OUT_FOR_DELIVERY', 'COMPLETED'],
            'OUT_FOR_DELIVERY' => ['COMPLETED'],
        ];
        return $map[$status] ?? [];
    }
}
