<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;
use PesanMenu\Exceptions\PaymentException;

final class QrisController extends Controller
{
    public function show(Request $request, ?string $token = null): void
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $token ??= (string) $request->query('token', '');
        $order = $this->orderForToken($token);
        if (!$order) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Pembayaran tidak ditemukan']);
            return;
        }
        $payment = $this->app->db()->one('SELECT * FROM payments WHERE order_id = ? LIMIT 1', [(int) $order['id']]);
        $this->app->render('public/qris', [
            'title' => 'Pembayaran QRIS',
            'order' => $order,
            'payment' => $payment,
            'token' => $token,
        ]);
    }

    public function retry(Request $request): void
    {
        $this->csrf($request);
        $token = (string) $request->post('token');
        $order = $this->orderForToken($token);
        if (!$order || ($order['payment_method'] ?? '') !== 'QRIS') {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Pembayaran tidak ditemukan']);
            return;
        }
        try {
            $this->app->paymentService()->createQrisCharge((int) $order['id']);
            Flash::set('success', 'QRIS berhasil dibuat ulang.');
        } catch (PaymentException $exception) {
            $this->app->log($exception);
            Flash::set('error', 'QRIS belum dapat dibuat. Coba lagi beberapa saat lagi.');
        }
        redirect('/qris?token=' . rawurlencode($token));
    }

    public function status(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        $token = (string) $request->query('token', '');
        $order = $this->orderForToken($token);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Pesanan tidak ditemukan'], JSON_THROW_ON_ERROR);
            return;
        }
        $payment = $this->app->db()->one('SELECT status, qr_url, expiry_at, failure_reason FROM payments WHERE order_id = ? LIMIT 1', [(int) $order['id']]);
        echo json_encode([
            'ok' => true,
            'order_status' => $order['status'],
            'payment_status' => $payment['status'] ?? 'PENDING',
            'qr_url' => $payment['qr_url'] ?? null,
            'expiry_at' => $payment['expiry_at'] ?? null,
            'message' => $payment['failure_reason'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    private function orderForToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return $this->orderService()->findByTrackingToken($token);
    }
}