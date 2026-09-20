<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Models\Job;
use App\Models\JobEvent;

/**
 * Application-level analytics: how reliably the platform publishes, how long
 * it takes, where it fails, and which machine performs.
 *
 * These are publishing-pipeline metrics. They are deliberately NOT presented
 * as Facebook engagement analytics, which would require data this system does
 * not collect (§65).
 */
final class AnalyticsService
{
    /** @return array<string,mixed> */
    public static function overview(int $userId, int $days = 30): array
    {
        $db = Database::instance();
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $totals = $db->first(
            "SELECT
                COUNT(*) AS total_jobs,
                SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') THEN 1 ELSE 0 END) AS action_required,
                SUM(attempts) AS total_attempts,
                AVG(CASE WHEN status = 'PUBLISHED' THEN attempts END) AS avg_attempts_to_succeed
             FROM jobs WHERE user_id = ? AND created_at >= ?",
            [$userId, $since]
        ) ?? [];

        $published = (int) ($totals['published'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);
        $finished = $published + $failed;

        return [
            'window_days'            => $days,
            'total_jobs'             => (int) ($totals['total_jobs'] ?? 0),
            'published'              => $published,
            'failed'                 => $failed,
            'action_required'        => (int) ($totals['action_required'] ?? 0),
            'success_rate'           => $finished > 0 ? round(($published / $finished) * 100, 1) : null,
            'retries'                => max(0, (int) ($totals['total_attempts'] ?? 0) - $finished),
            'avg_attempts_to_succeed' => isset($totals['avg_attempts_to_succeed']) && $totals['avg_attempts_to_succeed'] !== null
                ? round((float) $totals['avg_attempts_to_succeed'], 2)
                : null,
            'avg_publish_seconds'    => self::averageDurationSeconds($userId, $since),
            'stage_durations'        => JobEvent::stageDurations($userId, $days),
        ];
    }

    /** Average end-to-end job duration, computed in PHP for portability. */
    public static function averageDurationSeconds(int $userId, string $since, string $status = 'PUBLISHED'): ?float
    {
        $rows = Database::instance()->select(
            'SELECT started_at, completed_at FROM jobs
             WHERE user_id = ? AND status = ? AND started_at IS NOT NULL AND completed_at IS NOT NULL AND created_at >= ?',
            [$userId, $status, $since]
        );

        $samples = [];
        foreach ($rows as $row) {
            $start = Clock::parseUtc((string) $row['started_at'])?->getTimestamp();
            $end = Clock::parseUtc((string) $row['completed_at'])?->getTimestamp();
            if ($start !== null && $end !== null && $end >= $start) {
                $samples[] = $end - $start;
            }
        }

        return $samples === [] ? null : round(array_sum($samples) / count($samples), 1);
    }

    /** @return list<array<string,mixed>> */
    public static function failureCategories(int $userId, int $days = 30): array
    {
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        $codes = Database::instance()->select(
            "SELECT COALESCE(error_code, 'UNKNOWN') AS error_code, COUNT(*) AS occurrences
             FROM jobs WHERE user_id = ? AND status IN ('FAILED','USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED')
               AND created_at >= ?
             GROUP BY COALESCE(error_code, 'UNKNOWN') ORDER BY occurrences DESC LIMIT 20",
            [$userId, $since]
        );

        return array_map(static function (array $row): array {
            $code = (string) $row['error_code'];
            return [
                'code'        => $code,
                'label'       => JobFailureCodes::label($code),
                'retryable'   => JobFailureCodes::isRetryable($code),
                'occurrences' => (int) $row['occurrences'],
            ];
        }, $codes);
    }

    /** @return list<array<string,mixed>> */
    public static function byPage(int $userId, int $days = 30, int $limit = 25): array
    {
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        return Database::instance()->select(
            "SELECT f.id, f.page_name, f.status AS page_status, a.label AS account_label,
                    COUNT(j.id) AS jobs,
                    SUM(CASE WHEN j.status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) AS failed
             FROM facebook_pages f
             LEFT JOIN jobs j ON j.page_id = f.id AND j.created_at >= ?
             JOIN facebook_accounts a ON a.id = f.account_id
             WHERE f.user_id = ?
             GROUP BY f.id, f.page_name, f.status, a.label
             ORDER BY jobs DESC, f.page_name ASC LIMIT " . (int) $limit,
            [$since, $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function byAccount(int $userId, int $days = 30): array
    {
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        return Database::instance()->select(
            "SELECT a.id, a.label, a.status AS account_status,
                    COUNT(j.id) AS jobs,
                    SUM(CASE WHEN j.status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) AS failed
             FROM facebook_accounts a
             LEFT JOIN jobs j ON j.account_id = a.id AND j.created_at >= ?
             WHERE a.user_id = ?
             GROUP BY a.id, a.label, a.status
             ORDER BY jobs DESC",
            [$since, $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function workerPerformance(int $userId, int $days = 30): array
    {
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        return Database::instance()->select(
            "SELECT w.id, w.name, w.status, w.app_version,
                    COUNT(j.id) AS jobs,
                    SUM(CASE WHEN j.status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                    SUM(j.attempts) AS attempts
             FROM browser_workers w
             LEFT JOIN jobs j ON j.worker_id = w.id AND j.created_at >= ?
             WHERE w.user_id = ?
             GROUP BY w.id, w.name, w.status, w.app_version
             ORDER BY jobs DESC",
            [$since, $userId]
        );
    }

    /** Daily throughput sparkline data. @return list<array{day:string,published:int,failed:int}> */
    public static function dailyThroughput(int $userId, int $days = 14): array
    {
        $since = Clock::now()->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');
        $rows = Database::instance()->select(
            "SELECT substr(COALESCE(completed_at, scheduled_at), 1, 10) AS day,
                    SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed
             FROM jobs WHERE user_id = ? AND COALESCE(completed_at, scheduled_at) >= ?
             GROUP BY substr(COALESCE(completed_at, scheduled_at), 1, 10)
             ORDER BY day ASC",
            [$userId, $since]
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = $row;
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Clock::now()->modify("-{$i} days")->format('Y-m-d');
            $series[] = [
                'day'       => $day,
                'published' => (int) ($indexed[$day]['published'] ?? 0),
                'failed'    => (int) ($indexed[$day]['failed'] ?? 0),
            ];
        }

        return $series;
    }

    /** Platform-wide figures for the admin console. @return array<string,mixed> */
    public static function platformOverview(int $days = 30): array
    {
        $db = Database::instance();
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $jobs = $db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') THEN 1 ELSE 0 END) AS action_required
             FROM jobs WHERE created_at >= ?",
            [$since]
        ) ?? [];

        return [
            'users'        => (int) $db->scalar('SELECT COUNT(*) FROM users', []),
            'workers'      => (int) $db->scalar('SELECT COUNT(*) FROM browser_workers', []),
            'workers_online' => (int) $db->scalar("SELECT COUNT(*) FROM browser_workers WHERE status IN ('ONLINE','BUSY')", []),
            'accounts'     => (int) $db->scalar('SELECT COUNT(*) FROM facebook_accounts', []),
            'pages'        => (int) $db->scalar('SELECT COUNT(*) FROM facebook_pages', []),
            'posts'        => (int) $db->scalar('SELECT COUNT(*) FROM posts', []),
            'jobs_window'  => (int) ($jobs['total'] ?? 0),
            'published'    => (int) ($jobs['published'] ?? 0),
            'failed'       => (int) ($jobs['failed'] ?? 0),
            'action_required' => (int) ($jobs['action_required'] ?? 0),
            'storage_bytes' => (int) $db->scalar('SELECT COALESCE(SUM(size_bytes), 0) FROM media WHERE deleted_at IS NULL', []),
            'queue_depth'  => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('SCHEDULED','QUEUED','RETRYING')", []),
            'errors_24h'   => (int) $db->scalar(
                "SELECT COUNT(*) FROM worker_logs WHERE level = 'error' AND created_at >= ?",
                [Clock::now()->modify('-24 hours')->format('Y-m-d H:i:s')]
            ),
        ];
    }
}
