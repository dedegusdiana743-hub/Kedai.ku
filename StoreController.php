<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;

final class StoreController extends Controller
{
    public function show(Request $request, string $slug): void
    {
        $seller = $this->orderService()->sellerBySlug($slug);
        if (!$seller) {
            http_response_code(404);
            $this->app->render('errors/404', ['title' => 'Toko tidak ditemukan']);
            return;
        }

        $_SESSION['last_seller_id'] = (int) $seller['id'];
        $rows = $this->app->db()->all(
            'SELECT c.id AS category_id, c.name AS category_name, c.category_type,
                    mi.id, mi.name, mi.description, mi.price, mi.image_key, mi.is_available
             FROM categories c
             LEFT JOIN menu_items mi ON mi.category_id = c.id AND mi.seller_id = c.seller_id AND mi.deleted_at IS NULL
             WHERE c.seller_id = ?
             ORDER BY c.name, mi.name',
            [(int) $seller['id']]
        );
        $categories = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['category_id'];
            if (!isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'id' => $categoryId,
                    'name' => $row['category_name'],
                    'type' => $row['category_type'],
                    'items' => [],
                ];
            }
            if ($row['id'] !== null) {
                $categories[$categoryId]['items'][] = $row;
            }
        }

        $this->app->render('public/store', [
            'title' => $seller['name'],
            'seller' => $seller,
            'categories' => $categories,
        ]);
    }

    public function image(Request $request, string $filename): void
    {
        $path = $this->imageService()->path('menu/' . $filename);
        if (!$path) {
            http_response_code(404);
            return;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
    }
}
