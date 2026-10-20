<?php

declare(strict_types=1);

namespace PesanMenu\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $basePath = (string) ($this->config['base_path'] ?? '');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $path = '/' . trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->normalize($_POST[$key] ?? $_GET[$key] ?? $default, $default);
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->normalize($_POST[$key] ?? $default, $default);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->normalize($_GET[$key] ?? $default, $default);
    }

    public function json(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '' || strlen($raw) > 65536) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function allPost(): array
    {
        return is_array($_POST) ? $_POST : [];
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!empty($this->config['trust_proxy'])) {
            $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $candidate = $forwarded !== '' ? trim(explode(',', $forwarded)[0]) : '';
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $remote = $candidate;
            }
        }

        return substr($remote, 0, 45);
    }

    private function normalize(mixed $value, mixed $default): mixed
    {
        return is_array($value) || is_object($value) ? $default : $value;
    }

    public function __construct(private readonly array $config = [])
    {
    }
}