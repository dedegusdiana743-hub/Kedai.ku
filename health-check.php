<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$result = (new PesanMenu\Services\HealthService($app))->check();
foreach ($result['checks'] as $check) {
    fwrite(STDOUT, sprintf("[%s] %s: %s\n", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['message']));
}
exit($result['ok'] ? 0 : 1);