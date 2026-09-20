<?php
declare(strict_types=1);

/**
 * LinkEasy Publisher — application configuration.
 *
 * Everything is env-driven with safe defaults so the app boots in three modes:
 *   - production  : MySQL/MariaDB, HTTPS, real workers
 *   - development : MySQL or SQLite, debug on
 *   - demo/test   : SQLite file, simulated publishing provider
 *
 * NEVER commit real secrets. Use config/local.php (git-ignored) or env vars.
 */

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
};

$config = [
    'app' => [
        'name'      => 'LinkEasy Publisher',
        'env'       => $env('APP_ENV', 'production'),
        'debug'     => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
        'url'       => rtrim((string) $env('APP_URL', 'http://localhost:8080'), '/'),
        'key'       => (string) $env('APP_KEY', ''),
        'timezone'  => 'UTC',   // storage timezone; NEVER change at runtime
        'version'   => '1.0.0',
    ],

    'database' => [
        // mysql | sqlite
        'driver'   => (string) $env('DB_DRIVER', 'mysql'),
        'host'     => (string) $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', 3306),
        'database' => (string) $env('DB_DATABASE', 'linkeasy'),
        'username' => (string) $env('DB_USERNAME', 'linkeasy'),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        // SQLite only (demo + automated tests)
        'sqlite_path' => (string) $env('DB_SQLITE_PATH', dirname(__DIR__) . '/storage/framework/linkeasy.sqlite'),
    ],

    'session' => [
        'name'          => 'linkeasy_session',
        'lifetime'      => 60 * 60 * 8,
        'secure'        => filter_var($env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'samesite'      => 'Lax',
        'idle_timeout'  => 60 * 60 * 2,
    ],

    'security' => [
        'password_algo'          => PASSWORD_ARGON2ID,
        'password_options'       => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2],
        'max_login_attempts'     => 5,
        'lockout_minutes'        => 15,
        'csrf_token_ttl'         => 60 * 60 * 4,
        'worker_token_prefix'    => 'lkw_',
        'worker_token_bytes'     => 32,
        'heartbeat_interval_s'   => 20,
        'worker_offline_after_s' => 120,
    ],

    'uploads' => [
        'disk_path'        => dirname(__DIR__) . '/storage/uploads',
        'max_image_bytes'  => 25 * 1024 * 1024,
        'max_video_bytes'  => 4 * 1024 * 1024 * 1024,
        'chunk_bytes'      => 4 * 1024 * 1024,
        'allowed_images'   => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_videos'   => ['video/mp4', 'video/quicktime'],
        'allowed_ext'      => ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'],
    ],

    'queue' => [
        'lease_seconds'          => 900,     // 15 min job lease
        'max_attempts'           => 3,
        'claim_batch_size'       => 5,
        'backoff_schedule'       => [60, 300, 900],  // exponential-ish retry backoff
        'stale_grace_seconds'    => 120,
        'paused_status'          => 'PAUSED',
    ],

    'publishing' => [
        // browser | simulated  — 'simulated' runs the whole pipeline without
        // touching Facebook (see docs/README.md, Demo/Test Mode).
        'provider' => (string) $env('PUBLISHING_PROVIDER', 'browser'),
    ],

    'retention' => [
        'log_days'         => 30,
        'screenshot_days'  => 30,
        'trace_days'       => 14,
        'worker_log_days'  => 30,
    ],

    'storage' => [
        // Server-side default media strategy: SERVER | LOCAL | EXTERNAL | HYBRID
        'media_strategy' => (string) $env('MEDIA_STRATEGY', 'SERVER'),
    ],

    'logging' => [
        'path'  => dirname(__DIR__) . '/storage/logs',
        'level' => (string) $env('LOG_LEVEL', 'info'),
    ],
];

// Optional local override (never committed).
$local = __DIR__ . '/local.php';
if (is_file($local)) {
    /** @var array<string,mixed> $override */
    $override = require $local;
    $config = array_replace_recursive($config, $override);
}

return $config;
