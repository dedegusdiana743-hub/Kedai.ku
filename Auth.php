<?php

declare(strict_types=1);

namespace PesanMenu\Core;

final class Auth
{
    private ?array $user = null;
    private bool $loaded = false;
    private ?array $seller = null;
    private bool $sellerLoaded = false;

    public function __construct(private readonly App $app)
    {
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $now = time();
        $absoluteTimeout = (int) $this->app->config('app.session_absolute_timeout', 43200);
        $idleTimeout = (int) $this->app->config('app.session_idle_timeout', 3600);
        if (
            $now - (int) ($_SESSION['auth_time'] ?? 0) > $absoluteTimeout
            || $now - (int) ($_SESSION['last_activity'] ?? 0) > $idleTimeout
        ) {
            $this->logout();
            return null;
        }

        $this->user = $this->app->db()->one('SELECT id, email, name, status, token_version FROM users WHERE id = ? LIMIT 1', [$id]);
        if (
            ($this->user['status'] ?? null) !== 'ACTIVE'
            || (int) ($_SESSION['token_version'] ?? 0) !== (int) ($this->user['token_version'] ?? 0)
        ) {
            $this->logout();
            $this->user = null;
        }
        $_SESSION['last_activity'] = $now;

        return $this->user;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->app->db()->one('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
        if (!$user || ($user['status'] ?? '') !== 'ACTIVE' || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $this->login($user);
        return true;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['token_version'] = (int) ($user['token_version'] ?? 1);
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
        $this->user = $user;
        $this->loaded = true;
        $this->sellerLoaded = false;
        $this->seller = null;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['auth_time'], $_SESSION['last_activity'], $_SESSION['token_version']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->user = null;
        $this->loaded = true;
        $this->sellerLoaded = true;
        $this->seller = null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user ? (int) $user['id'] : null;
    }

    public function seller(): ?array
    {
        if ($this->sellerLoaded) {
            return $this->seller;
        }
        $this->sellerLoaded = true;
        $user = $this->user();
        if (!$user) {
            return null;
        }

        $this->seller = $this->app->db()->one(
            'SELECT s.*, su.role FROM seller_users su JOIN sellers s ON s.id = su.seller_id WHERE su.user_id = ? ORDER BY s.is_active DESC, s.id ASC LIMIT 1',
            [(int) $user['id']]
        );

        return $this->seller;
    }

    public function requireSeller(): array
    {
        $seller = $this->seller();
        if (!$seller) {
            header('Location: ' . $this->app->url('/penjual/login'));
            exit;
        }

        return $seller;
    }

    public function hasRole(string ...$roles): bool
    {
        $seller = $this->seller();
        return $seller !== null && in_array((string) $seller['role'], $roles, true);
    }
}
