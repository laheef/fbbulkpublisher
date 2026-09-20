<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Support;

/**
 * "Export Diagnostics" (§73).
 *
 * Produces LinkEasy-Diagnostics-YYYY-MM-DD.zip containing logs, version info,
 * system health, error summaries and non-sensitive configuration.
 *
 * Everything passes through Support::redact() and the credential blocklist
 * below, so passwords, cookies, worker tokens and session data can never leave
 * the server inside a diagnostics bundle.
 */
final class DiagnosticsService
{
    /** Keys whose values must never appear in an export. */
    private const SECRET_KEYS = [
        'password', 'password_hash', 'token', 'token_hash', 'secret', 'api_key',
        'app_key', 'cookie', 'authorization', 'bearer', 'csrf', 'session',
    ];

    /** @return array{path:string,filename:string,bytes:int,entries:list<string>} */
    public static function export(?int $userId = null): array
    {
        $base = dirname(__DIR__, 2) . '/storage/exports';
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('Unable to create the exports directory.');
        }

        $stamp = Clock::now()->format('Y-m-d');
        $filename = "LinkEasy-Diagnostics-{$stamp}.zip";
        $path = $base . '/' . $filename;

        if (!class_exists(\ZipArchive::class)) {
            // Fall back to a plain JSON report when the zip extension is absent.
            $jsonPath = $base . '/' . str_replace('.zip', '.json', $filename);
            file_put_contents($jsonPath, json_encode(self::collect($userId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return ['path' => $jsonPath, 'filename' => basename($jsonPath), 'bytes' => (int) filesize($jsonPath), 'entries' => ['diagnostics.json']];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the diagnostics archive.');
        }

        $entries = [];

        $zip->addFromString('diagnostics.json', (string) json_encode(self::collect($userId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $entries[] = 'diagnostics.json';

        $zip->addFromString('environment.txt', self::environmentReport());
        $entries[] = 'environment.txt';

        $zip->addFromString('database-summary.json', (string) json_encode(self::databaseSummary(), JSON_PRETTY_PRINT));
        $entries[] = 'database-summary.json';

        foreach (self::logFiles() as $file) {
            $contents = (string) @file_get_contents($file);
            // Never ship more than 2 MB of any single log.
            $zip->addFromString('logs/' . basename($file), Support::redact(mb_substr($contents, -2_000_000)));
            $entries[] = 'logs/' . basename($file);
        }

        $zip->addFromString('README.txt', self::readme());
        $entries[] = 'README.txt';

        $zip->close();
        @chmod($path, 0640);

        Logger::info('Diagnostics exported', ['user_id' => $userId, 'bytes' => filesize($path)]);

        return [
            'path'     => $path,
            'filename' => $filename,
            'bytes'    => (int) filesize($path),
            'entries'  => $entries,
        ];
    }

    /** @return array<string,mixed> */
    public static function collect(?int $userId = null): array
    {
        $db = Database::instance();

        return [
            'generated_at_utc' => Clock::now()->format(DATE_ATOM),
            'application'      => [
                'name'    => Config::str('app.name'),
                'version' => Config::str('app.version'),
                'env'     => Config::str('app.env'),
                'debug'   => Config::bool('app.debug'),
                'php'     => PHP_VERSION,
                'sapi'    => PHP_SAPI,
            ],
            'platform' => [
                'os'        => PHP_OS_FAMILY,
                'uname'     => php_uname(),
                'server'    => $_SERVER['SERVER_SOFTWARE'] ?? 'cli',
                'timezone'  => date_default_timezone_get(),
            ],
            'ffmpeg' => [
                'ffmpeg'  => MediaProbe::version('ffmpeg'),
                'ffprobe' => MediaProbe::version('ffprobe'),
            ],
            'configuration' => self::safeConfiguration(),
            'database'      => self::databaseSummary(),
            'counts'        => self::counts($userId),
            'health'        => self::health($userId),
        ];
    }

    /** @return array<string,mixed> */
    private static function safeConfiguration(): array
    {
        $config = Config::all();
        return self::scrub($config);
    }

    /**
     * Recursively remove any key that looks like a credential.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function scrub(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::scrub($value);
            } elseif (is_string($value)) {
                $out[$key] = Support::redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function databaseSummary(): array
    {
        $db = Database::instance();
        return [
            'driver' => $db->driver(),
            'tables' => self::counts(null),
            'queue'  => \App\Models\Job::summaryForUser(0),
            'error_codes_last_7_days' => $db->select(
                "SELECT COALESCE(error_code,'UNKNOWN') AS code, COUNT(*) AS c FROM jobs
                 WHERE status IN ('FAILED','USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') AND created_at >= ?
                 GROUP BY COALESCE(error_code,'UNKNOWN') ORDER BY c DESC LIMIT 25",
                [Clock::now()->modify('-7 days')->format('Y-m-d H:i:s')]
            ),
        ];
    }

    /** @return array<string,int> */
    private static function counts(?int $userId): array
    {
        $db = Database::instance();
        $scope = $userId === null ? '' : ' WHERE user_id = ' . (int) $userId;

        $tables = ['users', 'facebook_accounts', 'facebook_pages', 'media', 'posts', 'post_pages',
                   'scheduled_posts', 'jobs', 'job_events', 'notifications', 'activity_logs',
                   'worker_logs', 'browser_workers', 'settings'];

        $counts = [];
        foreach ($tables as $table) {
            $where = ($userId !== null && $table !== 'users' && $table !== 'job_events' && $table !== 'settings') ? $scope : '';
            try {
                $counts[$table] = (int) $db->scalar("SELECT COUNT(*) FROM {$table}{$where}", []);
            } catch (\Throwable) {
                $counts[$table] = -1;
            }
        }
        return $counts;
    }

    /** @return array<string,mixed> */
    private static function health(?int $userId): array
    {
        $db = Database::instance();
        $staleCutoff = Clock::now()->modify('-' . \App\Core\Config::int('security.worker_offline_after_s', 120) . ' seconds')->format('Y-m-d H:i:s');

        return [
            'database'        => true,
            'storage_writable' => is_writable(Config::str('uploads.disk_path')) || is_writable(dirname(Config::str('uploads.disk_path'))),
            'log_writable'    => is_writable(Config::str('logging.path')),
            'workers_online'  => (int) $db->scalar("SELECT COUNT(*) FROM browser_workers WHERE status IN ('ONLINE','BUSY')", []),
            'workers_stale'   => (int) $db->scalar(
                "SELECT COUNT(*) FROM browser_workers WHERE last_heartbeat_at IS NULL OR last_heartbeat_at < ?",
                [$staleCutoff]
            ),
            'queue_depth'     => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('SCHEDULED','QUEUED','RETRYING')", []),
            'stuck_jobs'      => (int) $db->scalar(
                "SELECT COUNT(*) FROM jobs WHERE status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING') AND lease_expires_at < ?",
                [Clock::nowString()]
            ),
        ];
    }

    /** @return list<string> */
    private static function logFiles(): array
    {
        $dir = Config::str('logging.path');
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.log') ?: [];
        // Include one rotation generation for context.
        $files = array_merge($files, glob($dir . '/*.log.1') ?: []);
        return array_values(array_filter($files, 'is_file'));
    }

    private static function environmentReport(): string
    {
        $lines = [
            'LinkEasy Publisher — Diagnostics',
            'Generated (UTC): ' . Clock::now()->format('Y-m-d H:i:s'),
            'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ')',
            'OS: ' . php_uname(),
            'Extensions: ' . implode(', ', array_slice(get_loaded_extensions(), 0, 60)),
            '',
            'This bundle never contains passwords, cookies, session data or worker tokens.',
        ];
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function readme(): string
    {
        return <<<TXT
LinkEasy Publisher — Diagnostics bundle
=======================================

Contents
  diagnostics.json       Version, environment, redacted configuration, health
  environment.txt        Runtime summary
  database-summary.json  Row counts, queue state, failure taxonomy (last 7 days)
  logs/                  Application, worker-mirror, browser and error logs

Privacy
  Passwords, cookies, session identifiers, Facebook credentials and worker
  tokens are removed before the archive is written. Log lines pass through
  the same redaction filter used by the logger.

Support
  Attach this archive to your support request together with a short
  description of what you expected and what happened.
TXT;
    }

    /** Remove exports older than N days. */
    public static function pruneExports(int $days = 14): int
    {
        $dir = dirname(__DIR__, 2) . '/storage/exports';
        if (!is_dir($dir)) {
            return 0;
        }
        $cutoff = Clock::now()->modify("-{$days} days")->getTimestamp();
        $removed = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }
}
