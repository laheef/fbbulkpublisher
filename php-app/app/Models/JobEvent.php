<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

/**
 * Append-only state-transition log for a job. Used by the queue timeline UI
 * and by the analytics service (stage durations, retry counts, failure codes).
 */
final class JobEvent extends Model
{
    protected static string $table = 'job_events';
    protected static array $fillable = ['job_id', 'from_state', 'to_state', 'stage', 'message', 'actor'];

    public static function record(
        int $jobId,
        ?string $fromState,
        string $toState,
        ?string $stage = null,
        ?string $message = null,
        string $actor = 'system'
    ): void {
        static::db()->insert('job_events', [
            'job_id'     => $jobId,
            'from_state' => $fromState,
            'to_state'   => $toState,
            'stage'      => $stage === null ? null : mb_substr($stage, 0, 64),
            'message'    => $message === null ? null : mb_substr(Support::redact($message), 0, 1000),
            'actor'      => mb_substr($actor, 0, 64),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function timeline(int $jobId, int $limit = 200): array
    {
        return static::db()->select(
            'SELECT * FROM job_events WHERE job_id = ? ORDER BY id ASC LIMIT ' . (int) $limit,
            [$jobId]
        );
    }

    /**
     * Average wall-clock seconds spent per stage, used by Analytics.
     * Computed in PHP so the query behaves identically on MySQL and SQLite.
     *
     * @return list<array{state:string,events:int,average_seconds:float}>
     */
    public static function stageDurations(int $userId, int $days = 30): array
    {
        $since = Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $rows = static::db()->select(
            'SELECT e.job_id, e.to_state, e.created_at
             FROM job_events e JOIN jobs j ON j.id = e.job_id
             WHERE j.user_id = ? AND e.created_at >= ?
             ORDER BY e.job_id ASC, e.id ASC',
            [$userId, $since]
        );

        /** @var array<string,list<float>> $durations */
        $durations = [];
        $previous = [];   // job_id => [state, timestamp]

        foreach ($rows as $row) {
            $jobId = (int) $row['job_id'];
            $ts = Clock::parseUtc((string) $row['created_at'])?->getTimestamp();
            if ($ts === null) {
                continue;
            }

            if (isset($previous[$jobId])) {
                [$prevState, $prevTs] = $previous[$jobId];
                $delta = (float) ($ts - $prevTs);
                if ($delta >= 0 && $delta < 86400) {
                    $durations[$prevState][] = $delta;
                }
            }
            $previous[$jobId] = [(string) $row['to_state'], $ts];
        }

        $result = [];
        foreach ($durations as $state => $samples) {
            $result[] = [
                'state'           => $state,
                'events'          => count($samples),
                'average_seconds' => round(array_sum($samples) / max(1, count($samples)), 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['events'] <=> $a['events']);
        return $result;
    }
}
