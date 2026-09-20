<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Logger;
use App\Models\BrowserWorker;
use App\Models\Job;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Services\Publishing\ProviderFactory;

/**
 * The PHP scheduler is the single source of truth for timing (§16).
 *
 * One tick:
 *   1. promote time-due jobs from SCHEDULED to QUEUED
 *   2. expand due schedule entries into jobs (idempotent per run)
 *   3. release leases held by workers that stopped heart-beating
 *   4. mark silent workers OFFLINE
 *   5. roll post statuses up
 *
 * Run it every minute from cron or a hosted scheduler.
 */
final class SchedulerService
{
    /** @return array<string,mixed> */
    public static function tick(bool $verbose = false): array
    {
        $started = microtime(true);
        $stats = [
            'promoted_jobs'    => Job::releaseDueScheduled(200),
            'expanded_schedules' => 0,
            'skipped_schedules'  => 0,
            'released_leases'  => BrowserWorker::releaseExpiredLeases(),
            'workers_offline'  => BrowserWorker::sweepOffline(\App\Core\Config::int('security.worker_offline_after_s', 120)),
            'errors'           => 0,
        ];

        foreach (ScheduledPost::due(50) as $entry) {
            try {
                if (!ScheduledPost::claim($entry)) {
                    $stats['skipped_schedules']++;
                    continue;
                }

                $runIndex = (int) $entry['runs_count'] + 1;
                $pageIds = self::resolvePages((int) $entry['post_id'], $entry['page_id'] === null ? null : (int) $entry['page_id']);

                if ($pageIds === []) {
                    static::failSchedule((int) $entry['id'], 'No Pages are attached to this post.');
                    $stats['errors']++;
                    continue;
                }

                $result = PostDispatcher::dispatch((int) $entry['user_id'], (int) $entry['post_id'], $pageIds, [
                    'when'            => Clock::nowString(),
                    'stagger_seconds' => (int) $entry['stagger_seconds'],
                    'salt'            => 'schedule:' . $entry['id'] . ':' . $runIndex,
                ]);

                $stats['expanded_schedules'] += $result['queued'] + $result['scheduled'];

                ScheduledPost::completeIfExhausted((int) $entry['id']);

                if ($verbose) {
                    Logger::info('Schedule expanded', [
                        'schedule_id' => (int) $entry['id'],
                        'post_id'     => (int) $entry['post_id'],
                        'jobs'        => $result['queued'] + $result['scheduled'],
                        'run'         => $runIndex,
                    ]);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                static::failSchedule((int) $entry['id'], $e->getMessage());
                Logger::error('Schedule expansion failed', ['schedule_id' => $entry['id'], 'error' => $e->getMessage()]);
            }
        }

        // Refresh the parent post status for anything that moved this tick.
        foreach (\App\Core\Database::instance()->select(
            "SELECT DISTINCT post_id FROM jobs WHERE updated_at >= ?",
            [Clock::now()->modify('-5 minutes')->format('Y-m-d H:i:s')]
        ) as $row) {
            Post::rollUpStatus((int) $row['post_id']);
        }

        $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $stats;
    }

    /** @return list<int> */
    private static function resolvePages(int $postId, ?int $pageId): array
    {
        if ($pageId !== null) {
            return [$pageId];
        }
        $rows = \App\Core\Database::instance()->select('SELECT page_id FROM post_pages WHERE post_id = ?', [$postId]);
        return array_map(static fn (array $r): int => (int) $r['page_id'], $rows);
    }

    private static function failSchedule(int $scheduleId, string $message): void
    {
        \App\Core\Database::instance()->update('scheduled_posts', [
            'status'     => 'ERROR',
            'last_error' => mb_substr($message, 0, 500),
        ], ['id' => $scheduleId]);
    }

    /**
     * Bulk sequential scheduling helper (§30): spread N posts across M Pages
     * with a fixed spacing, entirely server-side.
     *
     * @param list<array{post_id:int,page_ids:list<int>}> $items
     * @return array<string,mixed>
     */
    public static function bulkSequential(int $userId, array $items, string $startLocal, string $timezone, int $spacingSeconds, ?int $counter = null): array
    {
        $cursor = Clock::parseUtc(Clock::localToUtc($startLocal, $timezone)) ?? Clock::now();
        $created = 0;
        $jobIds = [];

        foreach ($items as $item) {
            $result = PostDispatcher::dispatch($userId, (int) $item['post_id'], (array) $item['page_ids'], [
                'when'            => $cursor->format('Y-m-d H:i:s'),
                'stagger_seconds' => 0,
            ]);
            $created += $result['queued'] + $result['scheduled'];
            $jobIds = array_merge($jobIds, $result['job_ids']);
            $cursor = $cursor->modify('+' . max(1, $spacingSeconds) . ' seconds');
        }

        return ['jobs' => $created, 'job_ids' => $jobIds, 'last_at' => $cursor->format(DATE_ATOM)];
    }

    /** Retention / housekeeping pass (§37, §54). @return array<string,int> */
    public static function housekeeping(): array
    {
        $cfg = \App\Core\Config::all();
        $retention = (array) ($cfg['retention'] ?? []);

        $stats = [
            'jobs_purged'         => 0,
            'notifications'       => \App\Models\Notification::purgeOld((int) ($retention['log_days'] ?? 30)),
            'activity_logs'       => \App\Models\ActivityLog::purgeOld((int) ($retention['log_days'] ?? 30)),
            'worker_logs'         => \App\Models\WorkerLog::purgeOld((int) ($retention['worker_log_days'] ?? 30)),
            'login_attempts'      => \App\Models\User::purgeOldLoginAttempts(7),
            'orphan_media_pruned' => MediaService::pruneOrphanedFiles(),
        ];

        return $stats;
    }
}
