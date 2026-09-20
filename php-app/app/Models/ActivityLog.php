<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Support;

final class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';
    protected static array $fillable = [
        'user_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id', 'ip_address', 'meta',
    ];

    /** @param array<string,mixed> $meta */
    public static function record(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $ip = null,
        string $actorType = 'user',
        ?int $actorId = null,
        array $meta = []
    ): int {
        return static::db()->insert('activity_logs', [
            'user_id'     => $userId,
            'actor_type'  => in_array($actorType, ['user', 'worker', 'system'], true) ? $actorType : 'system',
            'actor_id'    => $actorId,
            'action'      => mb_substr($action, 0, 120),
            'entity_type' => $entityType === null ? null : mb_substr($entityType, 0, 64),
            'entity_id'   => $entityId,
            'ip_address'  => $ip === null ? null : mb_substr($ip, 0, 45),
            'meta'        => $meta === [] ? null : json_encode(
                array_map(static fn ($v) => is_string($v) ? Support::redact($v) : $v, $meta),
                JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        return static::db()->select(
            'SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 100): array
    {
        return static::db()->select(
            'SELECT a.*, u.email AS user_email, u.name AS user_name
             FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit,
            []
        );
    }

    public static function purgeOld(int $days): int
    {
        return static::db()->query(
            'DELETE FROM activity_logs WHERE created_at < ?',
            [Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s')]
        )->rowCount();
    }
}
