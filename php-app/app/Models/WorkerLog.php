<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

/**
 * Log lines shipped up from a Windows worker. Redacted on the way in so a
 * compromised worker cannot use the log channel to exfiltrate session data
 * into the dashboard.
 */
final class WorkerLog extends Model
{
    protected static string $table = 'worker_logs';
    protected static array $fillable = ['worker_id', 'user_id', 'channel', 'level', 'message', 'context'];

    public const CHANNELS = ['app', 'worker', 'browser', 'scheduler', 'errors'];
    public const LEVELS = ['debug', 'info', 'warn', 'error'];

    /** @param array<string,mixed> $context */
    public static function ingest(int $workerId, int $userId, string $channel, string $level, string $message, array $context = []): int
    {
        return static::db()->insert('worker_logs', [
            'worker_id' => $workerId,
            'user_id'   => $userId,
            'channel'   => in_array($channel, self::CHANNELS, true) ? $channel : 'worker',
            'level'     => in_array($level, self::LEVELS, true) ? $level : 'info',
            'message'   => mb_substr(Support::redact($message), 0, 2000),
            'context'   => $context === [] ? null : json_encode(
                array_map(static fn ($v) => is_string($v) ? Support::redact($v) : $v, $context),
                JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forWorker(int $workerId, int $limit = 200): array
    {
        return static::db()->select(
            'SELECT * FROM worker_logs WHERE worker_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit,
            [$workerId]
        );
    }

    /** @return array<string,mixed> */
    public static function stats(int $workerId): array
    {
        $row = static::db()->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors,
                    SUM(CASE WHEN level = 'warn' THEN 1 ELSE 0 END) AS warnings
             FROM worker_logs WHERE worker_id = ?",
            [$workerId]
        ) ?? [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'errors'   => (int) ($row['errors'] ?? 0),
            'warnings' => (int) ($row['warnings'] ?? 0),
        ];
    }

    public static function purgeOld(int $days): int
    {
        return static::db()->query(
            'DELETE FROM worker_logs WHERE created_at < ?',
            [Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s')]
        )->rowCount();
    }
}
