<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'development',
        'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'url' => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
        'base_path' => rtrim((string) (getenv('APP_BASE_PATH') ?: ''), '/'),
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Jakarta',
        'session_name' => getenv('SESSION_NAME') ?: 'pesanmenu_session',
        'trust_proxy' => filter_var(getenv('TRUST_PROXY') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'public_host' => strtolower(trim((string) (getenv('PUBLIC_HOST') ?: ''))),
        'public_host_allow_any' => filter_var(getenv('PUBLIC_HOST_ALLOW_ANY') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'trusted_hosts' => strtolower(trim((string) (getenv('TRUSTED_HOSTS') ?: ''))),
        'order_expiry_minutes' => max(5, (int) (getenv('ORDER_EXPIRY_MINUTES') ?: 30)),
        'session_idle_timeout' => max(300, (int) (getenv('SESSION_IDLE_TIMEOUT') ?: 3600)),
        'session_absolute_timeout' => max(900, (int) (getenv('SESSION_ABSOLUTE_TIMEOUT') ?: 43200)),
        'retention_days' => max(30, (int) (getenv('DATA_RETENTION_DAYS') ?: 180)),
    ],
    'payments' => [
        'mode' => strtoupper((string) (getenv('PAYMENT_MODE') ?: 'COD')) === 'QRIS' ? 'QRIS' : 'COD',
        'midtrans_server_key' => (string) (getenv('MIDTRANS_SERVER_KEY') ?: ''),
        'midtrans_production' => filter_var(getenv('MIDTRANS_PRODUCTION') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'midtrans_acquirer' => strtolower((string) (getenv('MIDTRANS_ACQUIRER') ?: 'gopay')),
        'http_timeout' => max(5, (int) (getenv('PAYMENT_HTTP_TIMEOUT') ?: 20)),
    ],
    'mail' => [
        'driver' => strtolower((string) (getenv('MAIL_DRIVER') ?: 'log')),
        'from' => (string) (getenv('MAIL_FROM') ?: 'no-reply@pesanmenu.local'),
        'smtp_host' => (string) (getenv('SMTP_HOST') ?: ''),
        'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
        'smtp_username' => (string) (getenv('SMTP_USERNAME') ?: ''),
        'smtp_password' => (string) (getenv('SMTP_PASSWORD') ?: ''),
        'smtp_encryption' => strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')),
        'smtp_timeout' => max(5, (int) (getenv('SMTP_TIMEOUT') ?: 15)),
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_DATABASE') ?: 'pesanmenu',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'timezone' => getenv('DB_TIMEZONE') ?: '+07:00',
    ],
];
