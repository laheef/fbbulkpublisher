<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Key/value settings with three scopes: global, per-user, per-worker.
 */
final class Setting extends Model
{
    protected static string $table = 'settings';
    protected static array $fillable = ['scope', 'user_id', 'worker_id', 'key_name', 'value'];

    public const DEFAULTS = [
        'max_concurrent_browsers' => '2',
        'max_concurrent_jobs'     => '2',
        'max_jobs_per_worker'     => '10',
        'idle_browser_timeout_s'  => '300',
        'trace_mode'              => 'failures_only',
        'debug_mode'              => '0',
        'verbose_logs'            => '0',
        'capture_screenshots'     => '1',
        'start_with_windows'      => '1',
        'minimize_to_tray'        => '1',
        'auto_update'             => '1',
        'media_strategy'          => 'SERVER',
        'cleanup_after_publish'   => '1',
        'retention_screenshots_d' => '30',
        'retention_traces_d'      => '14',
        'default_timezone'        => 'UTC',
        'worker_poll_interval_s'  => '15',
    ];

    public static function getValue(string $key, ?string $default = null, string $scope = 'global', ?int $userId = null, ?int $workerId = null): ?string
    {
        $row = static::db()->first(
            'SELECT value FROM settings WHERE scope = ? AND key_name = ? AND '
            . '(user_id IS NULL OR user_id = ?) AND (worker_id IS NULL OR worker_id = ?) '
            . 'ORDER BY worker_id IS NULL ASC, user_id IS NULL ASC LIMIT 1',
            [$scope, $key, $userId, $workerId]
        );

        if ($row !== null && $row['value'] !== null) {
            return (string) $row['value'];
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function setValue(string $key, ?string $value, string $scope = 'global', ?int $userId = null, ?int $workerId = null): void
    {
        $db = static::db();
        $existing = $db->first(
            'SELECT id FROM settings WHERE scope = ? AND key_name = ? AND '
            . ($userId === null ? 'user_id IS NULL' : 'user_id = ?') . ' AND '
            . ($workerId === null ? 'worker_id IS NULL' : 'worker_id = ?') . ' LIMIT 1',
            array_values(array_filter([$scope, $key, $userId, $workerId], static fn ($v) => $v !== null))
        );

        if ($existing !== null) {
            $db->update('settings', ['value' => $value], ['id' => (int) $existing['id']]);
            return;
        }

        $db->insert('settings', [
            'scope'     => $scope,
            'user_id'   => $userId,
            'worker_id' => $workerId,
            'key_name'  => $key,
            'value'     => $value,
        ]);
    }

    /**
     * Effective configuration for a worker: global defaults, overridden by the
     * user's preferences, overridden by machine-specific values.
     *
     * @return array<string,mixed>
     */
    public static function effectiveForWorker(int $userId, ?int $workerId = null): array
    {
        $config = self::DEFAULTS;

        $rows = static::db()->select(
            'SELECT key_name, value, scope, user_id, worker_id FROM settings
             WHERE (scope = \'global\') OR (scope = \'user\' AND user_id = ?) OR (scope = \'worker\' AND worker_id = ?)
             ORDER BY CASE scope WHEN \'global\' THEN 1 WHEN \'user\' THEN 2 ELSE 3 END ASC',
            [$userId, $workerId]
        );

        $typed = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            $config[(string) $row['key_name']] = $value;
            $typed[(string) $row['key_name']] = is_numeric($value) ? (float) $value : $value;
        }

        // Cast well-known keys so the client receives real numbers/booleans.
        foreach (['max_concurrent_browsers', 'max_concurrent_jobs', 'max_jobs_per_worker',
                  'idle_browser_timeout_s', 'worker_poll_interval_s', 'retention_screenshots_d',
                  'retention_traces_d'] as $key) {
            $config[$key] = (int) ($config[$key] ?? 0);
        }
        foreach (['debug_mode', 'verbose_logs', 'capture_screenshots', 'start_with_windows',
                  'minimize_to_tray', 'auto_update', 'cleanup_after_publish'] as $key) {
            $config[$key] = in_array(strtolower((string) ($config[$key] ?? '0')), ['1', 'true', 'yes', 'on'], true);
        }

        return $config;
    }

    /** @return list<array<string,mixed>> */
    public static function allForUser(int $userId): array
    {
        return static::db()->select(
            "SELECT * FROM settings WHERE scope = 'global' OR (scope = 'user' AND user_id = ?) ORDER BY key_name ASC",
            [$userId]
        );
    }
}
