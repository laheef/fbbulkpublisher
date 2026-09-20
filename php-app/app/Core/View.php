<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny template engine. Views are plain PHP files rendered inside a layout.
 * All output is escaped with Support::e() in the templates.
 */
final class View
{
    private static string $viewPath = '';
    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function boot(string $viewPath): void
    {
        self::$viewPath = rtrim($viewPath, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderRaw($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderRaw($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function renderRaw(string $view, array $data = []): string
    {
        $file = self::$viewPath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
