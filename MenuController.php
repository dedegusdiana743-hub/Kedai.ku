<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;
use RuntimeException;

final class MenuController extends Controller
{
    public function index(Request $request): void
    {
        $seller = $this->seller();
        $rows = $this->app->db()->all(
            'SELECT mi.*, c.name AS category_name FROM menu_items mi JOIN categories c ON c.id = mi.category_id WHERE mi.seller_id = ? AND mi.deleted_at IS NULL ORDER BY c.name, mi.name',
            [(int) $seller['id']]
        );
        $this->app->render('seller/menu/index', [
            'title' => 'Kelola menu',
            'seller' => $seller,
            'items' => $rows,
            'categories' => $this->categories((int) $seller['id']),
        ]);
    }

    public function create(Request $request): void
    {
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $this->app->render('seller/menu/form', [
            'title' => 'Tambah menu',
            'seller' => $seller,
            'item' => null,
            'categories' => $this->categories((int) $seller['id']),
        ]);
    }

    public function store(Request $request): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $imageKey = null;
        try {
            $data = $this->validated($request, (int) $seller['id']);
            if (isset($_FILES['image'])) {
                $imageKey = $this->imageService()->store($_FILES['image']);
            }
            $this->app->db()->transaction(function ($db) use ($seller, $data, $imageKey): void {
                $db->execute(
                    'INSERT INTO menu_items (seller_id, category_id, name, description, price, image_key, is_available) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [(int) $seller['id'], $data['category_id'], $data['name'], $data['description'], $data['price'], $imageKey, $data['is_available']]
                );
                $db->execute(
                    'INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)',
                    [(int) $seller['id'], $this->app->auth()->id(), 'menu.created', 'menu_item', $db->lastInsertId()]
                );
            });
            $this->flashSuccess('Menu berhasil ditambahkan.');
            redirect('/penjual/menu');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($imageKey) {
                $this->imageService()->delete($imageKey);
            }
            $old = $request->allPost();
            $old['is_available'] = isset($_POST['is_available']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError($exception->getMessage());
            redirect('/penjual/menu/tambah');
        }
    }

    public function edit(Request $request, string $id): void
    {
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $item = $this->app->db()->one('SELECT * FROM menu_items WHERE id = ? AND seller_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id, (int) $seller['id']]);
        if (!$item) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Menu tidak ditemukan']);
            return;
        }
        $this->app->render('seller/menu/form', [
            'title' => 'Ubah menu',
            'seller' => $seller,
            'item' => $item,
            'categories' => $this->categories((int) $seller['id']),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $item = $this->app->db()->one('SELECT * FROM menu_items WHERE id = ? AND seller_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id, (int) $seller['id']]);
        if (!$item) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Menu tidak ditemukan']);
            return;
        }
        $newImage = null;
        try {
            $data = $this->validated($request, (int) $seller['id']);
            $newImage = isset($_FILES['image']) ? $this->imageService()->store($_FILES['image']) : null;
            $imageKey = $newImage ?? $item['image_key'];
            $this->app->db()->transaction(function ($db) use ($seller, $data, $imageKey, $id): void {
                $db->execute(
                    'UPDATE menu_items SET category_id = ?, name = ?, description = ?, price = ?, image_key = ?, is_available = ? WHERE id = ? AND seller_id = ?',
                    [$data['category_id'], $data['name'], $data['description'], $data['price'], $imageKey, $data['is_available'], (int) $id, (int) $seller['id']]
                );
                $db->execute(
                    'INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)',
                    [(int) $seller['id'], $this->app->auth()->id(), 'menu.updated', 'menu_item', (int) $id]
                );
            });
            if ($newImage) {
                $this->imageService()->delete($item['image_key']);
            }
            $this->flashSuccess('Menu berhasil diperbarui.');
            redirect('/penjual/menu');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($newImage) {
                $this->imageService()->delete($newImage);
            }
            $old = $request->allPost();
            $old['is_available'] = isset($_POST['is_available']) ? '1' : '0';
            unset($old['_token']);
            remember_old($old);
            $this->flashError($exception->getMessage());
            redirect('/penjual/menu/' . (int) $id . '/ubah');
        }
    }

    public function destroy(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $item = $this->app->db()->one('SELECT id, image_key FROM menu_items WHERE id = ? AND seller_id = ? AND deleted_at IS NULL LIMIT 1', [(int) $id, (int) $seller['id']]);
        if ($item) {
            $this->app->db()->transaction(function ($db) use ($seller, $id): void {
                $db->execute('UPDATE menu_items SET deleted_at = NOW(), is_available = 0 WHERE id = ? AND seller_id = ?', [(int) $id, (int) $seller['id']]);
                $db->execute('INSERT INTO audit_logs (seller_id, actor_user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)', [(int) $seller['id'], $this->app->auth()->id(), 'menu.deleted', 'menu_item', (int) $id]);
            });
            $this->imageService()->delete($item['image_key']);
            $this->flashSuccess('Menu dinonaktifkan dan dihapus dari katalog.');
        }
        redirect('/penjual/menu');
    }

    private function categories(int $sellerId): array
    {
        return $this->app->db()->all('SELECT id, name, category_type FROM categories WHERE seller_id = ? ORDER BY name', [$sellerId]);
    }

    private function validated(Request $request, int $sellerId): array
    {
        $name = trim((string) $request->post('name'));
        $description = trim((string) $request->post('description'));
        $categoryId = (int) $request->post('category_id');
        $priceRaw = trim((string) $request->post('price'));
        if ($name === '' || mb_strlen($name) > 140) {
            throw new InvalidArgumentException('Nama menu wajib diisi dan maksimal 140 karakter.');
        }
        if (mb_strlen($description) > 1000) {
            throw new InvalidArgumentException('Deskripsi maksimal 1.000 karakter.');
        }
        if (!preg_match('/^\d{1,15}$/', $priceRaw)) {
            throw new InvalidArgumentException('Harga harus berupa angka rupiah tanpa pemisah.');
        }
        $category = $this->app->db()->one('SELECT id FROM categories WHERE id = ? AND seller_id = ?', [$categoryId, $sellerId]);
        if (!$category) {
            throw new InvalidArgumentException('Kategori tidak valid.');
        }

        return [
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'category_id' => $categoryId,
            'price' => (int) $priceRaw,
            'is_available' => isset($_POST['is_available']) ? 1 : 0,
        ];
    }
}
