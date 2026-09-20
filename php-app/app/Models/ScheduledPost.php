<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;

/**
 * A schedule entry. PHP is the scheduling authority: the cron runner expands
 * due entries into jobs exactly once, guarded by next_run_at (UTC).
 */
final class ScheduledPost extends Model
{
    protected static string $table = 'scheduled_posts';
    protected static array $fillable = [
        'user_id', 'post_id', 'page_id', 'timezone', 'scheduled_at', 'recurrence',
        'interval_value', 'stagger_seconds', 'next_run_at', 'last_run_at', 'runs_count',
        'max_runs', 'status', 'last_error',
    ];

    public const RECURRENCES = ['NONE', 'EVERY_X_MINUTES', 'EVERY_X_HOURS', 'CUSTOM'];

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return static::db()->select(
            "SELECT s.*, p.caption, p.kind, p.page_count, p.status AS post_status
             FROM scheduled_posts s JOIN posts p ON p.id = s.post_id
             WHERE s.user_id = ? AND s.status IN ('SCHEDULED','PAUSED')
             ORDER BY s.next_run_at ASC LIMIT 500",
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> Due entries, oldest first. */
    public static function due(int $limit = 50): array
    {
        return static::db()->select(
            "SELECT * FROM scheduled_posts
             WHERE status = 'SCHEDULED' AND next_run_at IS NOT NULL AND next_run_at <= ?
             ORDER BY next_run_at ASC LIMIT " . (int) $limit,
            [Clock::nowString()]
        );
    }

    /**
     * Claim a due entry so concurrent cron processes cannot double-expand it.
     * The atomic UPDATE ... WHERE next_run_at = expected is the guard.
     *
     * @param array<string,mixed> $entry
     */
    public static function claim(array $entry): bool
    {
        $affected = static::db()->query(
            "UPDATE scheduled_posts
             SET next_run_at = ?, last_run_at = ?, runs_count = runs_count + 1
             WHERE id = ? AND status = 'SCHEDULED' AND next_run_at = ?",
            [
                self::computeNextRun($entry),
                Clock::nowString(),
                (int) $entry['id'],
                (string) $entry['next_run_at'],
            ]
        )->rowCount();

        return $affected === 1;
    }

    /** @param array<string,mixed> $entry */
    private static function computeNextRun(array $entry): ?string
    {
        $runs = (int) $entry['runs_count'] + 1;
        $maxRuns = $entry['max_runs'] === null ? null : (int) $entry['max_runs'];

        if ((string) $entry['recurrence'] === 'NONE') {
            return null;
        }
        if ($maxRuns !== null && $runs >= $maxRuns) {
            return null;
        }

        $interval = (int) ($entry['interval_value'] ?? 0);
        if ($interval <= 0) {
            return null;
        }

        $base = Clock::parseUtc((string) $entry['next_run_at']) ?? Clock::now();
        return match ((string) $entry['recurrence']) {
            'EVERY_X_MINUTES' => $base->modify("+{$interval} minutes")->format('Y-m-d H:i:s'),
            'EVERY_X_HOURS'   => $base->modify("+{$interval} hours")->format('Y-m-d H:i:s'),
            'CUSTOM'          => $base->modify("+{$interval} minutes")->format('Y-m-d H:i:s'),
            default           => null,
        };
    }

    public static function completeIfExhausted(int $scheduleId): void
    {
        $entry = static::find($scheduleId);
        if ($entry === null) {
            return;
        }
        if ($entry['next_run_at'] === null) {
            static::db()->update('scheduled_posts', ['status' => 'COMPLETED'], ['id' => $scheduleId]);
        }
    }

    public static function pause(int $scheduleId): void
    {
        static::db()->update('scheduled_posts', ['status' => 'PAUSED'], ['id' => $scheduleId]);
    }

    public static function resume(int $scheduleId): void
    {
        $entry = static::find($scheduleId);
        if ($entry === null) {
            return;
        }
        $next = $entry['next_run_at'] ?? null;
        if ($next === null || Clock::isPast((string) $next)) {
            $next = Clock::nowString();
        }
        static::db()->update('scheduled_posts', ['status' => 'SCHEDULED', 'next_run_at' => $next], ['id' => $scheduleId]);
    }

    public static function cancel(int $scheduleId): void
    {
        static::db()->update('scheduled_posts', ['status' => 'CANCELLED'], ['id' => $scheduleId]);
    }
}
