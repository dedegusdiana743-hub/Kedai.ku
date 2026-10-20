<?php

declare(strict_types=1);

namespace PesanMenu\Core;

use PesanMenu\Services\MidtransClient;
use PesanMenu\Services\PaymentService;
use PesanMenu\Controllers\AuthController;
use PesanMenu\Controllers\CartController;
use PesanMenu\Controllers\CategoryController;
use PesanMenu\Controllers\CheckoutController;
use PesanMenu\Controllers\DashboardController;
use PesanMenu\Controllers\HomeController;
use PesanMenu\Controllers\MenuController;
use PesanMenu\Controllers\OrderController;
use PesanMenu\Controllers\PasswordResetController;
use PesanMenu\Controllers\PaymentNotificationController;
use PesanMenu\Controllers\QrisController;
use PesanMenu\Controllers\ReportController;
use PesanMenu\Controllers\SettingsController;
use PesanMenu\Controllers\StoreController;
use PesanMenu\Controllers\TrackingController;
use Throwable;

final class App
{
    private static ?App $instance = null;
    private Router $router;
    private View $view;
    private Request $request;
    private Database $db;
    private Auth $auth;

    public function __construct(private readonly array $config, private readonly string $root)
    {
        self::$instance = $this;
        $this->router = new Router();
        $this->view = new View($root . '/views');
        $this->request = new Request($this->config['app']);
        $this->db = new Database($this->config['db']);
        $this->auth = new Auth($this);
    }

    public static function instance(): App
    {
        if (!self::$instance instanceof self) {
            throw new \RuntimeException('Aplikasi belum diinisialisasi.');
        }

        return self::$instance;
    }

    public function run(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://api.midtrans.com https://api.sandbox.midtrans.com; connect-src 'self'");
            if ($this->requestScheme() === 'https') {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
        try {
            $this->registerRoutes();
            $this->router->dispatch($this->request, $this);
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    public function controller(string $class): object
    {
        return new $class($this);
    }

    public function render(string $template, array $data = [], string $layout = 'layouts/main'): void
    {
        $data['app'] = $this;
        $this->view->render($template, $data, $layout);
    }

    public function url(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $configuredBase = rtrim((string) ($this->config['app']['url'] ?? ''), '/');
        $requestBase = $this->publicRequestBase($configuredBase);
        $base = $requestBase ?? $configuredBase;
        if ($path === '') {
            return $base !== '' ? $base : '/';
        }

        return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
    }

    private function publicRequestBase(string $configuredBase): ?string
    {
        $requestHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($requestHost === '' || !preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $requestHost)) {
            return null;
        }

        if ($this->config('app.public_host_allow_any', false)) {
            return $this->requestScheme() . '://' . $requestHost;
        }

        $requestName = strtolower((string) parse_url('http://' . $requestHost, PHP_URL_HOST));
        if ($this->isTrustedPrivateHost($requestName)) {
            return $this->requestScheme() . '://' . $requestHost;
        }

        $allowedHosts = array_filter(array_map('trim', explode(',', (string) $this->config('app.public_host', ''))));
        if (!$allowedHosts) {
            return null;
        }
        $requestName = strtolower((string) parse_url('http://' . $requestHost, PHP_URL_HOST));
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(ltrim($allowed, '.'));
            if ($allowed === '') {
                continue;
            }
            if ($requestName === $allowed || str_ends_with($requestName, '.' . $allowed)) {
                return $this->requestScheme() . '://' . $requestHost;
            }
        }

        return null;
    }

    private function isTrustedPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        $trusted = array_filter(array_map('trim', explode(',', (string) $this->config('app.trusted_hosts', ''))));
        if (in_array($host, array_map('strtolower', $trusted), true)) {
            return true;
        }
        $localAddresses = array_filter([gethostbyname((string) gethostname())]);
        return in_array($host, array_map('strtolower', $localAddresses), true) && $this->isPrivateHost($host);
    }

    private function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '10.')
                || str_starts_with($host, '192.168.')
                || (str_starts_with($host, '172.') && (int) substr($host, 4, 2) >= 16 && (int) substr($host, 4, 2) <= 31);
        }
        return false;
    }

    private function requestScheme(): string
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }
        if (!$this->config('app.trust_proxy', false)) {
            return 'http';
        }
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        return $forwarded === 'https' ? 'https' : 'http';
    }

    public function asset(string $path): string
    {
        return $this->url('/assets/' . ltrim($path, '/'));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function paymentsEnabled(): bool
    {
        return $this->config('payments.mode') === 'QRIS'
            && (string) $this->config('payments.midtrans_server_key', '') !== '';
    }

    public function paymentService(): PaymentService
    {
        $gateway = new MidtransClient(
            (string) $this->config('payments.midtrans_server_key', ''),
            (bool) $this->config('payments.midtrans_production', false),
            (string) $this->config('payments.midtrans_acquirer', 'gopay'),
            (int) $this->config('payments.http_timeout', 20)
        );

        return new PaymentService($this->db, $gateway, (int) $this->config('app.order_expiry_minutes', 30));
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function isDebug(): bool
    {
        return (bool) $this->config['app']['debug'];
    }

    public function log(Throwable $exception): void
    {
        $directory = $this->root . '/storage/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $line = sprintf(
            "[%s] %s in %s:%d\n%s\n\n",
            date('c'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        @file_put_contents($directory . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function handleException(Throwable $exception): void
    {
        $this->log($exception);
        http_response_code(500);
        try {
            $this->render('errors/500', [
                'title' => 'Terjadi kesalahan',
                'debugMessage' => $this->isDebug() ? $exception->getMessage() : null,
            ]);
        } catch (Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>Kesalahan</title><p>Terjadi kesalahan.</p>';
        }
    }

    private function registerRoutes(): void
    {
        $this->router->get('/', [HomeController::class, 'index']);
        $this->router->get('/toko/{slug}', [StoreController::class, 'show']);
        $this->router->get('/keranjang', [CartController::class, 'show']);
        $this->router->post('/keranjang/tambah', [CartController::class, 'add']);
        $this->router->post('/keranjang/ubah', [CartController::class, 'update']);
        $this->router->post('/keranjang/hapus', [CartController::class, 'remove']);
        $this->router->get('/checkout', [CheckoutController::class, 'show']);
        $this->router->post('/checkout/proses', [CheckoutController::class, 'store']);
        $this->router->get('/sukses', [CheckoutController::class, 'success']);
        $this->router->get('/qris', [QrisController::class, 'show']);
        $this->router->get('/qris/status', [QrisController::class, 'status']);
        $this->router->post('/qris/retry', [QrisController::class, 'retry']);
        $this->router->get('/qris/{token}', [QrisController::class, 'show']);
        $this->router->post('/payment/midtrans/notification', [PaymentNotificationController::class, 'midtrans']);
        $this->router->get('/lacak', [TrackingController::class, 'show']);
        $this->router->get('/lacak/{token}', [TrackingController::class, 'show']);

        $this->router->get('/penjual/login', [AuthController::class, 'showLogin']);
        $this->router->post('/penjual/login', [AuthController::class, 'login']);
        $this->router->get('/penjual/password', [PasswordResetController::class, 'changeForm']);
        $this->router->post('/penjual/password', [PasswordResetController::class, 'change']);
        $this->router->get('/penjual/lupa-password', [PasswordResetController::class, 'requestForm']);
        $this->router->post('/penjual/lupa-password', [PasswordResetController::class, 'sendLink']);
        $this->router->get('/penjual/reset-password', [PasswordResetController::class, 'resetForm']);
        $this->router->post('/penjual/reset-password', [PasswordResetController::class, 'reset']);
        $this->router->post('/penjual/logout', [AuthController::class, 'logout']);
        $this->router->get('/penjual', [DashboardController::class, 'index']);
        $this->router->get('/penjual/menu', [MenuController::class, 'index']);
        $this->router->get('/penjual/kategori', [CategoryController::class, 'index']);
        $this->router->post('/penjual/kategori', [CategoryController::class, 'store']);
        $this->router->post('/penjual/kategori/{id}/hapus', [CategoryController::class, 'destroy']);
        $this->router->get('/penjual/menu/tambah', [MenuController::class, 'create']);
        $this->router->post('/penjual/menu/tambah', [MenuController::class, 'store']);
        $this->router->get('/penjual/menu/{id}/ubah', [MenuController::class, 'edit']);
        $this->router->post('/penjual/menu/{id}/ubah', [MenuController::class, 'update']);
        $this->router->post('/penjual/menu/{id}/hapus', [MenuController::class, 'destroy']);
        $this->router->get('/penjual/pesanan', [OrderController::class, 'index']);
        $this->router->get('/penjual/pesanan/{id}', [OrderController::class, 'show']);
        $this->router->post('/penjual/pesanan/{id}/status', [OrderController::class, 'updateStatus']);
        $this->router->post('/penjual/pesanan/{id}/bayar', [OrderController::class, 'markPaid']);
        $this->router->post('/penjual/pesanan/{id}/refund', [OrderController::class, 'refund']);
        $this->router->get('/penjual/laporan', [ReportController::class, 'index']);
        $this->router->get('/penjual/pengaturan', [SettingsController::class, 'edit']);
        $this->router->post('/penjual/pengaturan', [SettingsController::class, 'update']);

        $this->router->get('/media/menu/{filename}', [StoreController::class, 'image']);
        $this->router->get('/health', [HomeController::class, 'health']);
        $this->router->get('/ready', [HomeController::class, 'ready']);
    }
}
