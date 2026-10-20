<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use RuntimeException;

final class ImageService
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $storagePath)
    {
    }

    public function store(array $file): ?string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload gambar gagal.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran gambar maksimal 2 MB.');
        }

        $temporary = (string) ($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary) || filesize($temporary) > self::MAX_BYTES) {
            throw new RuntimeException('Gambar tidak valid atau melebihi 2 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if (!$extension || @getimagesize($temporary) === false) {
            throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
        }

        $directory = $this->storagePath . '/menu';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Direktori upload tidak dapat dibuat.');
        }
        $key = 'menu/' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($temporary, $this->storagePath . '/' . $key)) {
            throw new RuntimeException('Gambar tidak dapat disimpan.');
        }

        return $key;
    }

    public function path(string $key): ?string
    {
        if (!preg_match('#^menu/[a-f0-9]{32}\.(jpg|png|webp)$#', $key)) {
            return null;
        }
        $path = $this->storagePath . '/' . $key;
        return is_file($path) ? $path : null;
    }

    public function delete(?string $key): void
    {
        if ($key && $this->path($key)) {
            @unlink($this->storagePath . '/' . $key);
        }
    }
}
