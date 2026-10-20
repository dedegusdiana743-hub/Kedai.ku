<?php

declare(strict_types=1);

namespace PesanMenu\Core;

final class Flash
{
    public static function set(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION['_flash'] ?? []);
    }
}
