<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/src/bootstrap.php';
$options = getopt('', [
    'email:',
    'password:',
    'name:',
    'slug:',
    'whatsapp:',
    'address:',
    'demo',
]);

$required = ['email', 'password', 'name', 'slug'];
foreach ($required as $key) {
    if (empty($options[$key])) {
        fwrite(STDERR, "Usage: php bin/create-seller.php --email=... --password=... --name=... --slug=... [--whatsapp=...] [--address=...] [--demo]\n");
        exit(1);
    }
}

$email = strtolower(trim((string) $options['email']));
$password = (string) $options['password'];
try {
    PesanMenu\Services\PasswordResetService::validatePassword($password);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
$name = trim((string) $options['name']);
$slug = strtolower(trim((string) $options['slug']));
$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
$slug = trim($slug, '-');
$whatsapp = trim((string) ($options['whatsapp'] ?? ''));
$address = trim((string) ($options['address'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $name === '' || $slug === '') {
    fwrite(STDERR, "Data tidak valid. Pastikan email valid, password minimal 8 karakter, nama dan slug terisi.\n");
    exit(1);
}

try {
    $result = $app->db()->transaction(function ($db) use ($email, $password, $name, $slug, $whatsapp, $address, $options): array {
        $db->execute('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)', [$email, password_hash($password, PASSWORD_DEFAULT), $name]);
        $userId = $db->lastInsertId();
        $db->execute('INSERT INTO sellers (name, slug, address, whatsapp) VALUES (?, ?, ?, ?)', [$name, $slug, $address ?: null, $whatsapp ?: null]);
        $sellerId = $db->lastInsertId();
        $db->execute('INSERT INTO seller_users (seller_id, user_id, role) VALUES (?, ?, ?)', [$sellerId, $userId, 'OWNER']);

        if (array_key_exists('demo', $options)) {
            $db->execute('INSERT INTO categories (seller_id, name, category_type) VALUES (?, ?, ?)', [$sellerId, 'Makanan', 'MAKANAN']);
            $foodCategory = $db->lastInsertId();
            $db->execute('INSERT INTO categories (seller_id, name, category_type) VALUES (?, ?, ?)', [$sellerId, 'Minuman', 'MINUMAN']);
            $drinkCategory = $db->lastInsertId();
            $db->execute('INSERT INTO menu_items (seller_id, category_id, name, description, price, is_available) VALUES (?, ?, ?, ?, ?, 1)', [$sellerId, $foodCategory, 'Nasi Goreng', 'Nasi goreng dengan telur dan ayam.', 18000]);
            $db->execute('INSERT INTO menu_items (seller_id, category_id, name, description, price, is_available) VALUES (?, ?, ?, ?, ?, 1)', [$sellerId, $foodCategory, 'Mie Goreng', 'Mie goreng dengan saus dan ayam.', 20000]);
            $db->execute('INSERT INTO menu_items (seller_id, category_id, name, description, price, is_available) VALUES (?, ?, ?, ?, ?, 1)', [$sellerId, $drinkCategory, 'Es Teh Manis', 'Es teh manis segar.', 5000]);
        }

        return ['user_id' => $userId, 'seller_id' => $sellerId];
    });
    echo "Seller berhasil dibuat.\n";
    echo "Email: {$email}\nToko: {$name} (/{$slug})\nSeller ID: {$result['seller_id']}\n";
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        fwrite(STDERR, "Email atau slug toko sudah digunakan.\n");
        exit(1);
    }
    fwrite(STDERR, "Gagal membuat seller: {$exception->getMessage()}\n");
    exit(1);
}
