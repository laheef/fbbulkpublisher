<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in server — development and evaluation only.
 *
 *   php -S 0.0.0.0:8080 -t public public/router.php
 *
 * Production deployments use Apache/.htaccess or the nginx config in
 * docs/nginx-example.conf, which send everything to public/index.php.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// Serve real static assets directly.
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}

require __DIR__ . '/index.php';
