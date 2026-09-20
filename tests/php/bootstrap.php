<?php

declare(strict_types=1);

/**
 * Test bootstrap: loads the application against a throwaway SQLite database.
 *
 * Nothing here touches MySQL, Facebook, or the network. The same models,
 * services and validators the application uses in production are exercised, so
 * a test failure is a real behavioural failure.
 */

$root = dirname(__DIR__, 2);
$app = $root . '/php-app';

$tmp = sys_get_temp_dir() . '/linkeasy-tests-' . getmypid();
if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

putenv('LINKEASY_TEST_ROOT=' . $tmp);
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $tmp . '/tests.sqlite');
putenv('PUBLISHING_PROVIDER=simulated');
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('LOG_LEVEL=error');
$_ENV['LINKEASY_TEST_ROOT'] = $tmp;

require $app . '/app/Core/Autoloader.php';
App\Core\Autoloader::register('App', $app . '/app');

App\Core\Config::load($app . '/config/config.php', $app . '/config/local.php');

foreach (['storage/framework', 'storage/logs', 'storage/uploads', 'storage/exports'] as $dir) {
    $path = $app . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

/** A fresh, empty schema. */
function db_reset(): void
{
    $path = (string) App\Core\Config::str('database.sqlite_path', '');
    if ($path !== '' && file_exists($path)) {
        @unlink($path);
    }
    App\Core\Database::reset();
    App\Core\SqliteSchema::install(App\Core\Database::instance());
}

function db(): App\Core\Database
{
    return App\Core\Database::instance();
}

/** One workspace: a user, a worker, an account and two enabled Pages. */
function seed_workspace(array $overrides = []): array
{
    $now = App\Core\Clock::nowString();
    $userId = db()->insert('users', [
        'name'          => $overrides['name'] ?? 'Test Operator',
        'email'         => $overrides['email'] ?? ('operator' . random_int(1000, 999999) . '@example.test'),
        'password_hash' => password_hash('Test-Password-123!', PASSWORD_DEFAULT),
        'role'          => $overrides['role'] ?? 'user',
        'status'        => 'ACTIVE',
        'timezone'      => 'Asia/Karachi',
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    $registration = App\Models\BrowserWorker::register(
        $userId,
        App\Core\Support::uuid4(),
        App\Core\Support::uuid4(),
        $overrides['worker_name'] ?? 'TEST-PC',
        ['os' => 'Windows 11', 'app_version' => '1.0.0', 'worker_version' => '1.0.0', 'ffmpeg_available' => true],
    );

    $worker = $registration['worker'];
    App\Models\BrowserWorker::heartbeat((int) $worker['id'], [
        'status'      => 'ONLINE',
        'cpu_pct'     => 5.0,
        'mem_mb'      => 320,
        'internet_ok' => true,
    ]);

    $accountId = db()->insert('facebook_accounts', [
        'user_id'     => $userId,
        'label'       => 'Test Business Account',
        'profile_ref' => 'acct_test_' . $userId,
        'worker_id'   => (int) $worker['id'],
        'status'      => 'CONNECTED',
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);

    $pageIds = [];
    foreach ([['Test Page One', 'ENABLED'], ['Test Page Two', 'ENABLED']] as $index => [$name, $status]) {
        $pageIds[] = db()->insert('facebook_pages', [
            'user_id'    => $userId,
            'account_id' => $accountId,
            'page_id'    => '1000000000000' . $index,
            'page_name'  => $name,
            'page_url'   => 'https://www.facebook.com/test-page-' . $index,
            'status'     => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [
        'user_id'    => $userId,
        'worker'     => $worker,
        'worker_token' => $registration['token'],
        'account_id' => $accountId,
        'page_ids'   => $pageIds,
    ];
}

/** A composed post targeting the given Pages. */
function seed_post(array $ctx, array $pageIds, array $attributes = []): array
{
    return App\Models\Post::compose((int) $ctx['user_id'], array_merge([
        'caption'  => $attributes['caption'] ?? 'Test caption for the scheduler',
        'kind'     => $attributes['kind'] ?? 'TEXT',
        'media_id' => $attributes['media_id'] ?? null,
        'settings' => [],
    ], $attributes), 'Asia/Karachi');
}

/** Queue one job for a post and Page, due immediately. */
function queue_job(array $ctx, array $pageIds, array $attributes = []): array
{
    $post = seed_post($ctx, $pageIds, $attributes['post'] ?? []);

    $result = App\Models\Job::enqueue(array_merge([
        'user_id'      => (int) $ctx['user_id'],
        'post_id'      => (int) $post['id'],
        'page_id'      => (int) $pageIds[0],
        'account_id'   => (int) $ctx['account_id'],
        'worker_id'    => (int) $ctx['worker']['id'],
        'job_type'     => $attributes['job_type'] ?? 'PUBLISH_POST',
        'scheduled_at' => App\Core\Clock::now()->modify('-1 minute')->format('Y-m-d H:i:s'),
    ]), $attributes['run_index'] ?? 0, $attributes['salt'] ?? 'test-salt');

    return $result['job'];
}
