<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $staticFile = __DIR__ . $requestPath;
    if ($requestPath !== '/' && is_file($staticFile)) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$app->run();
