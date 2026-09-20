<?php
declare(strict_types=1);

/**
 * Retention / cleanup pass — run hourly or nightly.
 *
 *   ​0 3 * * * php /var/www/linkeasy/php-app/cron/housekeeping.php
 *
 * Purges old notifications, activity rows, worker logs and login attempts, and
 * removes media files whose records were soft-deleted and are no longer
 * referenced by any live job or draft.
 */

require __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::init();
\App\Core\Autoloader::register('App', dirname(__DIR__) . '/app');

\App\Core\Config::load(dirname(__DIR__) . '/config/config.php');
date_default_timezone_set('UTC');

$app = \App\Core\Application::instance();
$app->boot(dirname(__DIR__));

try {
    $stats = \App\Services\SchedulerService::housekeeping();
    $pruned = \App\Services\DiagnosticsService::pruneExports(14);

    printf("[%s] housekeeping %s pruned_exports=%d%s", gmdate('Y-m-d H:i:s'), json_encode($stats), $pruned, PHP_EOL);

    exit(0);
} catch (\Throwable $e) {
    \App\Core\Logger::error('Housekeeping failed: ' . $e->getMessage());
    fwrite(STDERR, 'housekeeping error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
