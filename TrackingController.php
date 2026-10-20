<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;

final class TrackingController extends Controller
{
    public function show(Request $request, ?string $token = null): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $token ??= (string) $request->query('token', '');
        if ($token === '') {
            $this->app->render('public/tracking', [
                'title' => 'Lacak pesanan',
                'order' => null,
                'token' => '',
            ]);
            return;
        }

        $order = $this->orderService()->findByTrackingToken($token);
        if (!$order) {
            Flash::set('error', 'Tautan lacak tidak valid atau sudah tidak tersedia.');
            $this->app->render('public/tracking', [
                'title' => 'Lacak pesanan',
                'order' => null,
                'token' => '',
            ]);
            return;
        }
        $items = $this->orderService()->orderItems((int) $order['id'], (int) $order['seller_id']);
        $events = $this->orderService()->events((int) $order['id']);
        $this->app->render('public/tracking', [
            'title' => 'Lacak pesanan',
            'order' => $order,
            'items' => $items,
            'events' => $events,
            'token' => $token,
        ]);
    }
}
