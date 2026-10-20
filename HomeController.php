<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\Request;
use PesanMenu\Services\HealthService;

final class HomeController extends Controller
{
    public function index(Request $request): void
    {
        $sellers = $this->app->db()->all(
            'SELECT id, name, slug, address, whatsapp FROM sellers WHERE is_active = 1 ORDER BY name LIMIT 24'
        );
        $this->app->render('home', [
            'title' => 'PesanMenu',
            'sellers' => $sellers,
        ]);
    }

    public function health(Request $request): void
    {
        try {
            $this->app->db()->value('SELECT 1');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'unavailable'], JSON_THROW_ON_ERROR);
        }
    }

    public function ready(Request $request): void
    {
        $result = (new HealthService($this->app))->check();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($result['ok'] ? 200 : 503);
        echo json_encode($result, JSON_THROW_ON_ERROR);
    }
}
