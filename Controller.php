<?php

declare(strict_types=1);

namespace PesanMenu\Controllers;

use PesanMenu\Core\App;
use PesanMenu\Core\Csrf;
use PesanMenu\Core\Flash;
use PesanMenu\Core\Request;
use PesanMenu\Services\CartService;
use PesanMenu\Services\ImageService;
use PesanMenu\Services\OrderService;
use PesanMenu\Services\PricingService;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function csrf(Request $request): void
    {
        if (!Csrf::validate((string) $request->post('_token'))) {
            http_response_code(419);
            $this->app->render('errors/419', ['title' => 'Sesi formulir kedaluwarsa']);
            exit;
        }
    }

    protected function seller(): array
    {
        return $this->app->auth()->requireSeller();
    }

    protected function requireRole(string ...$roles): void
    {
        $this->seller();
        if (!$this->app->auth()->hasRole(...$roles)) {
            http_response_code(403);
            $this->app->render('errors/403', ['title' => 'Akses ditolak']);
            exit;
        }
    }

    protected function cart(): CartService
    {
        return new CartService();
    }

    protected function pricing(): PricingService
    {
        return new PricingService();
    }

    protected function orderService(): OrderService
    {
        return new OrderService(
            $this->app->db(),
            $this->pricing(),
            $this->cart(),
            (int) $this->app->config('app.order_expiry_minutes', 30),
            $this->app->paymentsEnabled()
        );
    }

    protected function imageService(): ImageService
    {
        return new ImageService($this->app->root() . '/storage/uploads');
    }

    protected function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    protected function flashError(string $message): void
    {
        Flash::set('error', $message);
    }

    protected function flashSuccess(string $message): void
    {
        Flash::set('success', $message);
    }
}
