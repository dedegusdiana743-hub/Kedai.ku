<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Request;
use PesanMenu\Core\Flash;

final class CartController extends Controller
{
    public function show(Request $request): void
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

        $cart = $this->cart()->all((int) $seller['id']);
        $rows = [];
        if ($cart) {
            $ids = array_keys($cart);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $menuRows = $this->app->db()->all(
                "SELECT id, name, price, image_key, is_available FROM menu_items
                 WHERE seller_id = ? AND id IN ($placeholders) AND deleted_at IS NULL",
                array_merge([(int) $seller['id']], $ids)
            );
            $foundIds = [];
            foreach ($menuRows as $row) {
                $menuId = (int) $row['id'];
                if ((int) $row['is_available'] !== 1) {
                    unset($cart[$menuId]);
                    $this->cart()->update((int) $seller['id'], $menuId, 0);
                    continue;
                }
                $row['quantity'] = $cart[$menuId];
                $rows[] = $row;
                $foundIds[] = $menuId;
            }
            foreach (array_diff(array_keys($cart), $foundIds) as $missingId) {
                $this->cart()->remove((int) $seller['id'], (int) $missingId);
            }
        }
        $deliveryType = in_array((string) $request->query('delivery', 'PICKUP'), ['PICKUP', 'DELIVERY'], true)
            ? (string) $request->query('delivery', 'PICKUP')
            : 'PICKUP';
        try {
            $totals = $this->pricing()->calculate($rows, $seller, $deliveryType);
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage() . ' Hubungi penjual untuk lebih lanjut.');
            redirect('/toko/' . rawurlencode((string) $seller['slug']));
        }

        $this->app->render('public/cart', [
            'title' => 'Keranjang',
            'seller' => $seller,
            'items' => $rows,
            'cart' => $cart,
            'totals' => $totals,
            'deliveryType' => $deliveryType,
        ]);
    }

    public function add(Request $request): void
    {
        $this->csrf($request);
        try {
            $seller = $this->requireSellerSlug((string) $request->post('seller_slug'));
            $menuId = (int) $request->post('menu_id');
            $quantity = max(1, min(99, (int) $request->post('quantity', 1)));
            $menu = $this->app->db()->one('SELECT id FROM menu_items WHERE id = ? AND seller_id = ? AND is_available = 1 AND deleted_at IS NULL', [$menuId, (int) $seller['id']]);
            if (!$menu) {
                throw new InvalidArgumentException('Menu tidak tersedia.');
            }
            $this->cart()->add((int) $seller['id'], $menuId, $quantity);
            Flash::set('success', 'Menu ditambahkan ke keranjang.');
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage());
        }
        redirect('/toko/' . rawurlencode((string) $request->post('seller_slug')) . '#menu-' . (int) $request->post('menu_id'));
    }

    public function update(Request $request): void
    {
        $this->csrf($request);
        try {
            $seller = $this->requireSellerSlug((string) $request->post('seller_slug'));
            $this->cart()->update((int) $seller['id'], (int) $request->post('menu_id'), (int) $request->post('quantity', 1));
            redirect('/keranjang?slug=' . rawurlencode((string) $seller['slug']));
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage());
            redirect('/');
        }
    }

    public function remove(Request $request): void
    {
        $this->csrf($request);
        try {
            $seller = $this->requireSellerSlug((string) $request->post('seller_slug'));
            $this->cart()->remove((int) $seller['id'], (int) $request->post('menu_id'));
            Flash::set('success', 'Menu dihapus dari keranjang.');
            redirect('/keranjang?slug=' . rawurlencode((string) $seller['slug']));
        } catch (InvalidArgumentException $exception) {
            Flash::set('error', $exception->getMessage());
            redirect('/');
        }
    }

    private function requireSellerSlug(string $slug): array
    {
        $seller = $this->orderService()->sellerBySlug($slug);
        if (!$seller) {
            throw new InvalidArgumentException('Toko tidak valid.');
        }
        return $seller;
    }
}
