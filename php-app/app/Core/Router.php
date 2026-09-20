<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routes are registered as METHOD path => handler.
 *
 * Path segments wrapped in {braces} are captured and handed to the controller
 * as an associative array. Handlers are [ClassName::class, 'method'] pairs.
 */
final class Router
{
    /** @var array<string, list<array{pattern:string, handler:array{0:class-string,1:string}, params:list<string>}>> */
    private array $routes = [];

    /** @var list<array{prefix:string, middleware:list<string>}> */
    private array $groupStack = [];

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $path = $prefix . '/' . ltrim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        $params = [];
        $pattern = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[strtoupper($method)][] = [
            'pattern'    => '#^' . $pattern . '$#',
            'handler'    => $handler,
            'params'     => $params,
            'middleware' => $middleware,
        ];
    }

    /** @param array{prefix?:string, middleware?:list<string>} $attributes */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = [
            'prefix'     => rtrim((string) ($attributes['prefix'] ?? ''), '/'),
            'middleware' => (array) ($attributes['middleware'] ?? []),
        ];
        $callback($this);
        array_pop($this->groupStack);
    }

    /**
     * @return array{handler:array{0:class-string,1:string}, params:array<string,string>, middleware:list<string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ([strtoupper($method), 'ANY'] as $verb) {
            foreach ($this->routes[$verb] ?? [] as $route) {
                if (preg_match($route['pattern'], $path, $matches) === 1) {
                    array_shift($matches);
                    /** @var array<string,string> $params */
                    $params = $route['params'] === [] ? [] : array_combine($route['params'], $matches);
                    return [
                        'handler'    => $route['handler'],
                        'params'     => $params,
                        'middleware' => $route['middleware'] ?? [],
                    ];
                }
            }
        }
        return null;
    }

    /** @return list<string> */
    public function registeredPaths(): array
    {
        $paths = [];
        foreach ($this->routes as $verb => $routes) {
            foreach ($routes as $route) {
                $paths[] = $verb . ' ' . $route['pattern'];
            }
        }
        return $paths;
    }
}
