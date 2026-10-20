<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;

final class CheckoutController extends Controller
{
    public function show(Request $request): void
    {
        $seller = $this->sellerForRequest($request);
        $cart = $this->cart()->all((int) $seller['id']);
        if (!$cart) {
            redirect('/keranjang?slug=' . rawurlencode((string) $seller['slug']));
        }

        $rows = $this->menuRows((int) $seller['id'], $cart);
        if (count($rows) !== count($cart)) {
            $validIds = array_map('intval', array_column($rows, 'id'));
            foreach (array_keys($cart) as $menuId) {
                if (!in_array((int) $menuId, $validIds, true)) {
                    $this->cart()->remove((int) $seller['id'], (int) $menuId);
                }
            }
            Flash::set('error', 'Beberapa menu tidak tersedia lagi dan telah dihapus dari keranjang.');
            redirect('/keranjang?slug=' . rawurlencode((string) $seller['slug']));
        }
        $requestedDelivery = (string) $request->query('delivery', '');
        if (in_array($requestedDelivery, ['PICKUP', 'DELIVERY'], true)) {
            $deliveryType = $requestedDelivery;
            if ($deliveryType === 'PICKUP') {
                clear_old();
            }
        } else {
            $deliveryType = old('delivery_type', 'PICKUP') === 'DELIVERY' ? 'DELIVERY' : 'PICKUP';
        }
        try {
            $totals = $this->pricing()->calculate($rows, $seller, $deliveryType);
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage() . ' Hubungi penjual untuk lebih lanjut.');
            redirect('/toko/' . rawurlencode((string) $seller['slug']));
        }
        $key = $_SESSION['checkout_key'] ?? bin2hex(random_bytes(32));
        $_SESSION['checkout_key'] = $key;

        $this->app->render('public/checkout', [
            'title' => 'Checkout',
            'seller' => $seller,
            'items' => $rows,
            'totals' => $totals,
            'deliveryType' => $deliveryType,
            'idempotencyKey' => $key,
            'qrisEnabled' => $this->app->paymentsEnabled(),
        ]);
    }

    public function store(Request $request): void
    {
        $this->csrf($request);
        $slug = (string) $request->post('seller_slug');
        $seller = $this->orderService()->sellerBySlug($slug);
        if (!$seller) {
            redirect('/');
        }

        $name = trim((string) $request->post('name'));
        $phone = trim((string) $request->post('phone'));
        $deliveryType = (string) $request->post('delivery_type', 'PICKUP');
        $address = trim((string) $request->post('address'));
        $notes = trim((string) $request->post('notes'));
        $paymentMethod = (string) $request->post('payment_method', 'COD');
        $idempotencyKey = (string) ($request->post('idempotency_key') ?: ($_SESSION['checkout_key'] ?? bin2hex(random_bytes(32))));

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Nama harus berisi 2–120 karakter.';
        }
        if (!preg_match('/^[0-9+() .-]{8,30}$/', $phone)) {
            $errors[] = 'Nomor WhatsApp tidak valid.';
        }
        if (!in_array($deliveryType, ['PICKUP', 'DELIVERY'], true)) {
            $errors[] = 'Metode pengiriman tidak valid.';
        }
        if ($deliveryType === 'DELIVERY' && (mb_strlen($address) < 8 || mb_strlen($address) > 500)) {
            $errors[] = 'Alamat wajib diisi dan maksimal 500 karakter untuk pesanan antar.';
        }
        if (mb_strlen($notes) > 500) {
            $errors[] = 'Catatan maksimal 500 karakter.';
        }
        if ($paymentMethod === 'QRIS' && !$this->app->paymentsEnabled()) {
            $errors[] = 'QRIS belum diaktifkan pada rilis ini.';
        }
        if (!in_array($paymentMethod, ['COD', 'QRIS'], true)) {
            $errors[] = 'Metode pembayaran tidak valid.';
        }
        if ($errors) {
            remember_old([
                'name' => mb_substr($name, 0, 120),
                'phone' => mb_substr($phone, 0, 30),
                'delivery_type' => $deliveryType,
                'address' => mb_substr($address, 0, 500),
                'notes' => mb_substr($notes, 0, 500),
            ]);
            Flash::set('error', implode(' ', $errors));
            redirect('/checkout?slug=' . rawurlencode($slug));
        }

        try {
            $result = $this->orderService()->createFromCart(
                (int) $seller['id'],
                ['name' => $name, 'phone' => $phone, 'address' => $address],
                $deliveryType,
                $notes !== '' ? $notes : null,
                $paymentMethod,
                $idempotencyKey
            );
            $this->cart()->clear((int) $seller['id']);
            unset($_SESSION['checkout_key']);
            clear_old();
            if ($result['tracking_token'] === '') {
                Flash::set('error', 'Pesanan sudah pernah dibuat, tetapi tautan lacak tidak tersedia pada sesi ini. Hubungi toko menggunakan nomor pesanan.');
                redirect('/lacak');
            }
            if ($paymentMethod === 'QRIS') {
                try {
                    $this->app->paymentService()->createQrisCharge((int) $result['order_id']);
                } catch (\Throwable $exception) {
                    $this->app->log($exception);
                    Flash::set('error', 'QRIS belum dapat dibuat. Pesanan tetap tersimpan dan dapat dicoba lagi dari halaman pembayaran.');
                }
                redirect('/qris?token=' . rawurlencode($result['tracking_token']));
            }
            redirect('/sukses?token=' . rawurlencode($result['tracking_token']));
        } catch (InvalidArgumentException $exception) {
            remember_old([
                'name' => mb_substr($name, 0, 120),
                'phone' => mb_substr($phone, 0, 30),
                'delivery_type' => $deliveryType,
                'address' => mb_substr($address, 0, 500),
                'notes' => mb_substr($notes, 0, 500),
            ]);
            Flash::set('error', $exception->getMessage());
            redirect('/checkout?slug=' . rawurlencode($slug));
        }
    }

    public function success(Request $request): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $token = (string) $request->query('token', '');
        $order = $token !== '' ? $this->orderService()->findByTrackingToken($token) : null;
        if (!$order) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Konfirmasi pesanan tidak ditemukan']);
            return;
        }
        $this->app->render('public/success', [
            'title' => 'Pesanan berhasil',
            'order' => $order,
            'token' => $token,
        ]);
    }

    private function sellerForRequest(Request $request): array
    {
        $slug = trim((string) $request->query('slug', ''));
        $seller = $slug !== '' ? $this->orderService()->sellerBySlug($slug) : null;
        $sellerId = (int) ($_SESSION['last_seller_id'] ?? 0);
        if (!$seller && $sellerId > 0) {
            $seller = $this->orderService()->sellerById($sellerId);
        }
        if (!$seller) {
            redirect('/');
        }
        return $seller;
    }

    private function menuRows(int $sellerId, array $cart): array
    {
        $ids = array_keys($cart);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->app->db()->all(
            "SELECT id, name, price, is_available FROM menu_items WHERE seller_id = ? AND id IN ($placeholders) AND is_available = 1 AND deleted_at IS NULL",
            array_merge([$sellerId], $ids)
        );
        $result = [];
        foreach ($rows as $row) {
            $row['quantity'] = $cart[(int) $row['id']];
            $result[] = $row;
        }
        return $result;
    }
}
