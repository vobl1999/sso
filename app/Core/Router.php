<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 极简路由。支持 {param} 形式的路径参数。
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable, keys:array}>> */
    private array $routes = [
        'GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => [],
    ];

    private $fallback = null;

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): void  { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    public function any(string $path, callable $handler): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            $this->add($m, $path, $handler);
        }
    }

    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }
        $keys = [];
        // 把 /admin/users/{id} 编译成正则
        $pattern = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '([^/]+)';
        }, $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'keys'    => $keys,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();
        $list   = $this->routes[$method] ?? [];

        foreach ($list as $route) {
            if (preg_match($route['pattern'], $path, $m) === 1) {
                array_shift($m);
                $params = [];
                foreach ($route['keys'] as $i => $key) {
                    $params[$key] = $m[$i] ?? null;
                }
                ($route['handler'])($request, $params);
                return;
            }
        }

        // 路径存在但方法不对 → 405，便于排查
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path) === 1) {
                    Response::error('该地址不支持此请求方法', 405);
                }
            }
        }

        if ($this->fallback !== null) {
            ($this->fallback)($request);
            return;
        }
        Response::error('页面不存在', 404);
    }
}
