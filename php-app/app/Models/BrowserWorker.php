<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Models\FacebookAccount;
use App\Models\FacebookPage;

/**
 * A registered Windows installation running the automation worker.
 *
 * The worker token is only ever stored as a SHA-256 hash here; the plaintext
 * is handed to the machine once (at registration / rotation) and protected
 * there with Windows DPAPI.
 */
final class BrowserWorker extends Model
{
    protected static string $table = 'browser_workers';
    protected static array $fillable = [
        'user_id', 'installation_id', 'worker_id', 'name', 'os', 'arch',
        'app_version', 'worker_version', 'playwright_version', 'browser_version', 'ffmpeg_version',
        'cpu_pct', 'mem_mb', 'internet_ok', 'server_ok', 'status', 'is_enabled',
        'max_concurrent_jobs', 'max_concurrent_browsers', 'current_job_id', 'pending_count',
        'token_hash', 'token_prefix', 'token_rotated_at', 'capabilities',
        'last_heartbeat_at', 'connected_at', 'last_error',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string,mixed>|null */
    public static function findByWorkerId(string $workerId): ?array
    {
        return static::db()->first('SELECT * FROM browser_workers WHERE worker_id = ? LIMIT 1', [$workerId]);
    }

    /** @return array<string,mixed>|null */
    public static function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return static::db()->first('SELECT * FROM browser_workers WHERE token_hash = ? LIMIT 1', [self::hashToken($token)]);
    }

    /**
     * Register (or re-register) a Windows installation. Rotating an existing
     * installation_id keeps the same worker row so history is preserved.
     *
     * @param array<string,mixed> $meta
     * @return array{worker:array<string,mixed>,token:string}
     */
    public static function register(int $userId, string $installationId, string $workerId, string $name, array $meta): array
    {
        $existing = static::db()->first('SELECT * FROM browser_workers WHERE installation_id = ? LIMIT 1', [$installationId]);

        $token = \App\Core\Config::str('security.worker_token_prefix', 'lkw_') . \App\Core\Support::token(\App\Core\Config::int('security.worker_token_bytes', 32));

        $payload = [
            'user_id'          => $userId,
            'installation_id'  => $installationId,
            'worker_id'        => $workerId,
            'name'             => $name,
            'os'               => (string) ($meta['os'] ?? php_uname('s') . ' ' . php_uname('r')),
            'arch'             => (string) ($meta['arch'] ?? php_uname('m')),
            'app_version'      => (string) ($meta['app_version'] ?? '0.0.0'),
            'worker_version'   => (string) ($meta['worker_version'] ?? '0.0.0'),
            'playwright_version' => (string) ($meta['playwright_version'] ?? ''),
            'browser_version'  => (string) ($meta['browser_version'] ?? ''),
            'ffmpeg_version'   => (string) ($meta['ffmpeg_version'] ?? ''),
            'status'           => 'STARTING',
            'is_enabled'       => 1,
            'token_hash'       => self::hashToken($token),
            'token_prefix'     => substr($token, 0, 8),
            'token_rotated_at' => Clock::nowString(),
            'capabilities'     => json_encode([
                'max_concurrent_jobs'     => (int) ($meta['max_concurrent_jobs'] ?? 2),
                'max_concurrent_browsers' => (int) ($meta['max_concurrent_browsers'] ?? 2),
                'trace_mode'              => (string) ($meta['trace_mode'] ?? 'failures_only'),
                'ffmpeg'                  => (bool) ($meta['ffmpeg_available'] ?? false),
            ]),
            'last_heartbeat_at' => Clock::nowString(),
            'connected_at'      => Clock::nowString(),
            'last_error'        => null,
        ];

        if ($existing !== null) {
            static::db()->update('browser_workers', $payload, ['id' => (int) $existing['id']]);
            $worker = static::find((int) $existing['id']);
        } else {
            $id = static::db()->insert('browser_workers', $payload);
            $worker = static::find($id);
        }

        self::reclaimOrphanedJobs((int) $worker['id']);

        return ['worker' => $worker ?? [], 'token' => $token];
    }

    /**
     * A machine that comes back online gets a fresh token. Any job it had
     * locked under the previous session is released, never duplicated — the
     * unique idempotency key guarantees a single publish per (post,page,run).
     */
    private static function reclaimOrphanedJobs(int $workerId): void
    {
        static::db()->query(
            "UPDATE jobs SET status = 'RETRYING', worker_id = NULL, locked_at = NULL, lease_expires_at = NULL,
                    next_attempt_at = ?, error_code = 'WORKER_RESTARTED',
                    error_message = 'Worker restarted before the job completed; requeued safely.'
             WHERE worker_id = ? AND status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')",
            [Clock::nowString(), $workerId]
        );
    }

    public static function rotateToken(int $workerId): ?string
    {
        $token = \App\Core\Config::str('security.worker_token_prefix', 'lkw_') . \App\Core\Support::token(32);
        static::db()->update('browser_workers', [
            'token_hash'       => self::hashToken($token),
            'token_prefix'     => substr($token, 0, 8),
            'token_rotated_at' => Clock::nowString(),
        ], ['id' => $workerId]);
        return $token;
    }

    public static function heartbeat(int $workerId, array $stats): void
    {
        $status = (string) ($stats['status'] ?? 'ONLINE');
        $allowed = ['INSTALLING', 'STARTING', 'ONLINE', 'BUSY', 'PAUSED', 'OFFLINE', 'UPDATING', 'ERROR'];
        if (!in_array($status, $allowed, true)) {
            $status = 'ONLINE';
        }

        static::db()->update('browser_workers', [
            'status'            => $status,
            'cpu_pct'           => isset($stats['cpu_pct']) ? round((float) $stats['cpu_pct'], 2) : null,
            'mem_mb'            => isset($stats['mem_mb']) ? (int) $stats['mem_mb'] : null,
            'internet_ok'       => !empty($stats['internet_ok']) ? 1 : 0,
            'server_ok'         => 1,
            'app_version'       => (string) ($stats['app_version'] ?? ''),
            'worker_version'    => (string) ($stats['worker_version'] ?? ''),
            'playwright_version' => (string) ($stats['playwright_version'] ?? ''),
            'browser_version'   => (string) ($stats['browser_version'] ?? ''),
            'ffmpeg_version'    => (string) ($stats['ffmpeg_version'] ?? ''),
            'pending_count'     => (int) ($stats['pending_count'] ?? 0),
            'current_job_id'    => $stats['current_job_id'] ?? null,
            'last_heartbeat_at' => Clock::nowString(),
            'last_error'        => isset($stats['last_error']) ? mb_substr((string) $stats['last_error'], 0, 500) : null,
        ], ['id' => $workerId]);
    }

    /** Mark workers offline whose heartbeat has gone stale. */
    public static function sweepOffline(int $afterSeconds): int
    {
        $cutoff = Clock::now()->modify("-{$afterSeconds} seconds")->format('Y-m-d H:i:s');
        return static::db()->query(
            "UPDATE browser_workers SET status = 'OFFLINE', server_ok = 0
             WHERE status NOT IN ('OFFLINE','INSTALLING') AND (last_heartbeat_at IS NULL OR last_heartbeat_at < ?)",
            [$cutoff]
        )->rowCount();
    }

    /** Release jobs whose lease expired (crashed worker, power loss, ...). */
    public static function releaseExpiredLeases(): int
    {
        return static::db()->query(
            "UPDATE jobs SET status = 'RETRYING', worker_id = NULL, locked_at = NULL, lease_expires_at = NULL,
                    attempts = attempts, next_attempt_at = ?,
                    error_code = COALESCE(error_code, 'LEASE_EXPIRED'),
                    error_message = COALESCE(error_message, 'Job lease expired; safely requeued.')
             WHERE status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')
               AND lease_expires_at IS NOT NULL AND lease_expires_at < ?",
            [Clock::nowString(), Clock::nowString()]
        )->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, int $limit = 50): array
    {
        return static::db()->select(
            'SELECT w.*, j.status AS current_job_status, j.job_type AS current_job_type
             FROM browser_workers w
             LEFT JOIN jobs j ON j.id = w.current_job_id
             WHERE w.user_id = ? ORDER BY w.last_heartbeat_at DESC LIMIT ' . (int) $limit,
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function onlineForUser(int $userId): array
    {
        return static::db()->select(
            "SELECT * FROM browser_workers WHERE user_id = ? AND status IN ('ONLINE','BUSY') AND is_enabled = 1
             ORDER BY pending_count ASC, last_heartbeat_at DESC",
            [$userId]
        );
    }

    /** @return array<string,mixed> */
    public static function fleetSummary(?int $userId = null): array
    {
        $where = $userId === null ? '1=1' : 'user_id = ' . (int) $userId;
        $row = static::db()->first(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('ONLINE','BUSY') THEN 1 ELSE 0 END) AS online,
                SUM(CASE WHEN status = 'PAUSED' THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) AS errored,
                SUM(CASE WHEN status = 'OFFLINE' THEN 1 ELSE 0 END) AS offline,
                COALESCE(SUM(pending_count), 0) AS pending_jobs,
                COALESCE(SUM(max_concurrent_jobs), 0) AS capacity
             FROM browser_workers WHERE {$where}",
            []
        ) ?? [];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'online'       => (int) ($row['online'] ?? 0),
            'paused'       => (int) ($row['paused'] ?? 0),
            'errored'      => (int) ($row['errored'] ?? 0),
            'offline'      => (int) ($row['offline'] ?? 0),
            'pending_jobs' => (int) ($row['pending_jobs'] ?? 0),
            'capacity'     => (int) ($row['capacity'] ?? 0),
        ];
    }
}
