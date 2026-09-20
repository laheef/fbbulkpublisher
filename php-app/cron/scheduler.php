<?php
declare(strict_types=1);

/**
 * Scheduler tick — run every minute.
 *
 *   * * * * * php /var/www/linkeasy/php-app/cron/scheduler.php >> /var/log/linkeasy-scheduler.log 2>&1
 *
 * Promotes due jobs, expands recurring schedules, releases expired leases and
 * marks silent workers offline. Safe to run concurrently: every claim is
 * guarded by an atomic UPDATE.
 */

require __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::init();
\App\Core\Autoloader::register('App', dirname(__DIR__) . '/app');

\App\Core\Config::load(dirname(__DIR__) . '/config/config.php');
date_default_timezone_set('UTC');

$app = \App\Core\Application::instance();
$app->boot(dirname(__DIR__));

try {
    $stats = \App\Services\SchedulerService::tick(true);

    printf(
        "[%s] tick ok promoted=%d expanded=%d leases=%d offline=%d errors=%d (%d ms)%s",
        gmdate('Y-m-d H:i:s'),
        $stats['promoted_jobs'],
        $stats['expanded_schedules'],
        $stats['released_leases'],
        $stats['workers_offline'],
        $stats['errors'],
        $stats['duration_ms'],
        PHP_EOL
    );

    exit(0);
} catch (\Throwable $e) {
    \App\Core\Logger::error('Scheduler tick failed: ' . $e->getMessage());
    fwrite(STDERR, 'scheduler error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
