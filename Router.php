<?php

declare(strict_types=1);

namespace PesanMenu\Core;

use Throwable;

final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:mixed}> */
    private array $routes = [];

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, mixed $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request, App $app): void
    {
        $path = $request->path();
        $methodNotAllowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method()) {
                $methodNotAllowed = true;
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[] = $value;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler) && count($handler) === 2) {
                $controller = $app->controller($handler[0]);
                $controller->{$handler[1]}($request, ...$params);
            } elseif (is_callable($handler)) {
                $handler($request, ...$params);
            } else {
                throw new \RuntimeException('Route handler tidak valid.');
            }
            return;
        }

        if ($methodNotAllowed) {
            http_response_code(405);
            header('Allow: GET, POST');
            $app->render('errors/405', ['title' => 'Metode tidak diizinkan'], 'layouts/main');
            return;
        }
        http_response_code(404);
        $app->render('errors/404', ['title' => 'Halaman tidak ditemukan'], 'layouts/main');
    }

    private function compile(string $pattern): string
    {
        $segments = explode('/', trim($pattern, '/'));
        $compiled = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match)) {
                $compiled[] = '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $compiled[] = preg_quote($segment, '#');
            }
        }

        return '#^/' . implode('/', $compiled) . '/?$#';
    }
}
