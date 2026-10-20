<?php

declare(strict_types=1);

namespace PesanMenu\Core;

use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private readonly string $viewPath)
    {
    }

    public function render(string $template, array $data = [], string $layout = 'layouts/main'): void
    {
        $content = $this->capture($template, $data);
        echo $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    private function capture(string $template, array $data): string
    {
        $file = $this->viewPath . '/' . trim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template tidak ditemukan: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}