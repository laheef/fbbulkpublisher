<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

/**
 * In-app notifications. The Windows client polls these to raise native Windows
 * toast notifications; the web UI shows the same feed.
 */
final class Notification extends Model
{
    protected static string $table = 'notifications';
    protected static array $fillable = ['user_id', 'level', 'title', 'body', 'link', 'job_id', 'read_at'];

    public const LEVELS = ['info', 'success', 'warning', 'error', 'action_required'];

    public static function push(int $userId, string $level, string $title, ?string $body = null, ?string $link = null, ?int $jobId = null): int
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        return static::db()->insert('notifications', [
            'user_id' => $userId,
            'level'   => $level,
            'title'   => mb_substr($title, 0, 190),
            'body'    => $body === null ? null : mb_substr(Support::redact($body), 0, 1000),
            'link'    => $link === null ? null : mb_substr($link, 0, 500),
            'job_id'  => $jobId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
        return static::db()->select($sql, [$userId]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) static::db()->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }

    /**
     * @param list<int> $ids
     */
    public static function markRead(int $userId, array $ids = []): int
    {
        $now = Clock::nowString();
        if ($ids === []) {
            return static::db()->query(
                'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
                [$now, $userId]
            )->rowCount();
        }

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return static::db()->query(
            "UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN ({$placeholders})",
            array_merge([$now, $userId], $ids)
        )->rowCount();
    }

    /**
     * Notifications created after a cursor — the desktop client's polling feed.
     *
     * @return list<array<string,mixed>>
     */
    public static function since(int $userId, ?string $sinceIso, int $limit = 20): array
    {
        $since = $sinceIso === null ? Clock::now()->modify('-1 day') : Clock::parseUtc($sinceIso) ?? Clock::now()->modify('-1 day');
        return static::db()->select(
            'SELECT * FROM notifications WHERE user_id = ? AND created_at > ? ORDER BY created_at ASC LIMIT ' . (int) $limit,
            [$userId, $since->format('Y-m-d H:i:s')]
        );
    }

    public static function purgeOld(int $days): int
    {
        return static::db()->query(
            'DELETE FROM notifications WHERE created_at < ?',
            [Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s')]
        )->rowCount();
    }
}
