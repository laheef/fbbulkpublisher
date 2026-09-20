<?php
declare(strict_types=1);

/**
 * LinkEasy Publisher — command line utility.
 *
 *   php bin/console.php migrate          create/upgrade the schema
 *   php bin/console.php demo             seed the demo workspace (no Facebook required)
 *   php bin/console.php user:create      create a workspace interactively
 *   php bin/console.php user:admin EMAIL grant administrator rights
 *   php bin/console.php tick             run one scheduler tick
 *   php bin/console.php housekeeping     run the retention pass
 *   php bin/console.php stats            print platform counters
 */

require __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::init();
\App\Core\Autoloader::register('App', dirname(__DIR__) . '/app');

use App\Core\Application;
use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Core\SqliteSchema;
use App\Models\User;
use App\Services\DemoSeeder;
use App\Services\SchedulerService;

Config::load(__DIR__ . '/../config/config.php');
date_default_timezone_set('UTC');

$app = Application::instance();
$app->boot(dirname(__DIR__));

$argvCopy = $argv;
array_shift($argvCopy);
$command = $argvCopy[0] ?? 'help';

$out = static function (string $line = ''): void {
    // Suppressed: piping into `head` closes stdout early and that is not an error.
    @fwrite(STDOUT, $line . PHP_EOL);
};

try {
    switch ($command) {
        case 'migrate':
            $db = Database::instance();
            if ($db->isSqlite()) {
                SqliteSchema::install($db);
                $out('SQLite schema installed at ' . Config::str('database.sqlite_path'));
            } else {
                $sql = (string) file_get_contents(__DIR__ . '/../../database/schema.sql');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    if (stripos($statement, 'SET ') === 0 || stripos($statement, '--') === 0) {
                        continue;
                    }
                    try {
                        $db->pdo()->exec($statement);
                    } catch (\PDOException $e) {
                        // CREATE TABLE IF NOT EXISTS keeps re-runs idempotent.
                        if (!str_contains($e->getMessage(), 'already exists')) {
                            throw $e;
                        }
                    }
                }
                $out('MySQL schema applied from database/schema.sql');
            }
            break;

        case 'demo':
            $result = DemoSeeder::seed(true);
            $out('Demo workspace ready.');
            $out('  Sign in at  ' . Config::str('app.url') . '/login');
            $out('  Email       ' . $result['email']);
            $out('  Password    ' . $result['password']);
            $out('  Pages       ' . $result['pages'] . ' (all simulated — nothing is published to Facebook)');
            break;

        case 'user:create':
            $name = $argvCopy[1] ?? null;
            $email = $argvCopy[2] ?? null;
            $password = $argvCopy[3] ?? null;
            $timezone = $argvCopy[4] ?? 'UTC';

            if ($name === null || $email === null || $password === null) {
                $out('Usage: php bin/console.php user:create "Name" email@example.com "Password123!" [Timezone]');
                exit(2);
            }

            $result = User::register($name, $email, $password, $timezone);
            if (!$result['ok']) {
                $out('Failed: ' . implode(' ', $result['errors'] ?? []));
                exit(1);
            }
            $out('Created user #' . (int) $result['user']['id'] . ' (' . $email . ')');
            break;

        case 'user:admin':
            $email = $argvCopy[1] ?? null;
            if ($email === null) {
                $out('Usage: php bin/console.php user:admin email@example.com');
                exit(2);
            }
            $user = User::findByEmail($email);
            if ($user === null) {
                $out('No such user.');
                exit(1);
            }
            User::modify((int) $user['id'], ['role' => 'admin']);
            $out($email . ' is now an administrator.');
            break;

        case 'tick':
            $stats = SchedulerService::tick(true);
            $out(json_encode($stats, JSON_PRETTY_PRINT));
            break;

        case 'housekeeping':
            $out(json_encode(SchedulerService::housekeeping(), JSON_PRETTY_PRINT));
            break;

        case 'stats':
            $db = Database::instance();
            $rows = [
                'users'              => 'SELECT COUNT(*) FROM users',
                'facebook_accounts'  => 'SELECT COUNT(*) FROM facebook_accounts',
                'facebook_pages'     => 'SELECT COUNT(*) FROM facebook_pages',
                'media'              => 'SELECT COUNT(*) FROM media',
                'posts'              => 'SELECT COUNT(*) FROM posts',
                'jobs'               => 'SELECT COUNT(*) FROM jobs',
                'jobs_published'     => "SELECT COUNT(*) FROM jobs WHERE status = 'PUBLISHED'",
                'jobs_pending'       => "SELECT COUNT(*) FROM jobs WHERE status IN ('SCHEDULED','QUEUED','RETRYING')",
                'jobs_failed'        => "SELECT COUNT(*) FROM jobs WHERE status = 'FAILED'",
                'jobs_action'        => "SELECT COUNT(*) FROM jobs WHERE status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED')",
                'browser_workers'    => 'SELECT COUNT(*) FROM browser_workers',
                'workers_online'     => "SELECT COUNT(*) FROM browser_workers WHERE status IN ('ONLINE','BUSY')",
            ];
            foreach ($rows as $label => $sql) {
                $out(str_pad($label, 20) . $db->scalar($sql, []));
            }
            break;

        default:
            $out('LinkEasy Publisher console');
            $out('');
            $out('  migrate        create or upgrade the schema');
            $out('  demo           seed a fully simulated demo workspace');
            $out('  user:create    create a workspace user');
            $out('  user:admin     grant administrator rights');
            $out('  tick           run one scheduler tick');
            $out('  housekeeping   run the retention pass');
            $out('  stats          print platform counters');
            break;
    }
} catch (\Throwable $e) {
    @fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
