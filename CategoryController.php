<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use InvalidArgumentException;
use PesanMenu\Core\Request;

final class CategoryController extends Controller
{
    public function index(Request $request): void
    {
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $categories = $this->app->db()->all(
            'SELECT c.*, COUNT(mi.id) AS menu_count FROM categories c LEFT JOIN menu_items mi ON mi.category_id = c.id AND mi.deleted_at IS NULL WHERE c.seller_id = ? GROUP BY c.id ORDER BY c.name',
            [(int) $seller['id']]
        );
        $this->app->render('seller/categories', [
            'title' => 'Kategori menu',
            'seller' => $seller,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $name = trim((string) $request->post('name'));
        $type = strtoupper((string) $request->post('category_type', 'MAKANAN'));
        if ($name === '' || mb_strlen($name) > 80 || !in_array($type, ['MAKANAN', 'MINUMAN'], true)) {
            $this->flashError('Nama atau jenis kategori tidak valid.');
            redirect('/penjual/kategori');
        }
        try {
            $this->app->db()->execute('INSERT INTO categories (seller_id, name, category_type) VALUES (?, ?, ?)', [(int) $seller['id'], $name, $type]);
            $this->flashSuccess('Kategori berhasil ditambahkan.');
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flashError('Kategori dengan nama tersebut sudah ada.');
            } else {
                throw $exception;
            }
        }
        redirect('/penjual/kategori');
    }

    public function destroy(Request $request, string $id): void
    {
        $this->csrf($request);
        $this->requireRole('OWNER', 'MANAGER');
        $seller = $this->seller();
        $categoryId = (int) $id;
        $used = (int) $this->app->db()->value('SELECT COUNT(*) FROM menu_items WHERE category_id = ? AND seller_id = ?', [$categoryId, (int) $seller['id']]);
        if ($used > 0) {
            $this->flashError('Kategori masih memiliki menu dan tidak dapat dihapus.');
        } else {
            $this->app->db()->execute('DELETE FROM categories WHERE id = ? AND seller_id = ?', [$categoryId, (int) $seller['id']]);
            $this->flashSuccess('Kategori dihapus.');
        }
        redirect('/penjual/kategori');
    }
}
