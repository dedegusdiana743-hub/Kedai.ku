<?php

declare(strict_types=1);

/**
 * Backup database PesanMenu.
 * Usage: php bin/backup-db.php [--keep-days=14]
 */

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$options = getopt('', ['keep-days:']);
$keepDays = max(1, (int) ($options['keep-days'] ?? 14));
$directory = $app->root() . '/storage/backups';
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Direktori backup tidak dapat dibuat.\n");
    exit(1);
}

$config = $app->config('db');
$database = (string) $config['name'];
$dumpBinary = (string) (getenv('MYSQLDUMP_BIN') ?: 'mysqldump');
$timestamp = date('Ymd-His');
$sqlFile = $directory . '/pesanmenu-' . $timestamp . '.sql';
$finalFile = $sqlFile;

$commandAvailable = function_exists('exec') && (
    is_file($dumpBinary)
    || trim((string) shell_exec('command -v ' . escapeshellarg($dumpBinary) . ' 2>/dev/null')) !== ''
    || trim((string) shell_exec('where ' . escapeshellarg($dumpBinary) . ' 2>nul')) !== ''
);
if ($commandAvailable) {
    $arguments = [
        $dumpBinary,
        '--host=' . $config['host'],
        '--port=' . $config['port'],
        '--user=' . $config['username'],
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--events',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--result-file=' . $sqlFile,
        $database,
    ];
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    putenv('MYSQL_PWD=' . $config['password']);
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    putenv('MYSQL_PWD');
    if ($status !== 0 || !is_file($sqlFile)) {
        fwrite(STDERR, "mysqldump gagal: " . implode(' ', $output) . "\n");
        exit(1);
    }
} else {
    dumpWithPdo($app->db(), $sqlFile);
}

if (function_exists('gzopen')) {
    $gz = gzopen($sqlFile . '.gz', 'wb9');
    $input = fopen($sqlFile, 'rb');
    if ($gz === false || $input === false) {
        fwrite(STDERR, "Backup gzip gagal.\n");
        exit(1);
    }
    while (!feof($input)) {
        gzwrite($gz, fread($input, 1048576));
    }
    fclose($input);
    gzclose($gz);
    unlink($sqlFile);
    $finalFile .= '.gz';
}

$checksum = hash_file('sha256', $finalFile);
file_put_contents($finalFile . '.sha256', $checksum . '  ' . basename($finalFile) . "\n");

foreach (glob($directory . '/pesanmenu-*') ?: [] as $file) {
    if ($file === $finalFile || $file === $finalFile . '.sha256' || !is_file($file)) {
        continue;
    }
    if (filemtime($file) < time() - ($keepDays * 86400)) {
        @unlink($file);
    }
}

echo "Backup selesai: {$finalFile}\n";
echo "SHA-256: {$checksum}\n";

function dumpWithPdo(PesanMenu\Core\Database $database, string $file): void
{
    $handle = fopen($file, 'wb');
    if ($handle === false) {
        throw new RuntimeException('File backup tidak dapat dibuat.');
    }
    $pdo = $database->pdo();
    fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = $database->all('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name');
    foreach ($tables as $table) {
        $name = (string) $table['table_name'];
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $name) . '`')->fetch();
        fwrite($handle, "DROP TABLE IF EXISTS `" . $name . "`;\n" . ($create['Create Table'] ?? '') . ";\n");
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '', $name) . '`');
        if ($rows === false) {
            continue;
        }
        $count = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_keys($row);
            $values = array_map(static fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row));
            fwrite($handle, 'INSERT INTO `' . $name . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $values) . ");\n");
            $count++;
        }
        $rows->closeCursor();
        fwrite($handle, "-- {$name}: {$count} rows\n\n");
    }
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
}