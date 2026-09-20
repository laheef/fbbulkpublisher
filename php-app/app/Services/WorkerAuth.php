<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Models\BrowserWorker;

/**
 * Authenticates Windows worker requests.
 *
 * Every /worker/* call must carry:
 *   Authorization: Bearer lkw_...        (worker token, hash-compared)
 *   X-LinkEasy-Worker: <worker uuid>
 *
 * Token checks use hash_equals (constant time) against the stored SHA-256.
 */
final class WorkerAuth
{
    /** @return array<string,mixed> Worker row for an authenticated request. */
    public static function authenticate(Request $request, bool $mustBeEnabled = true): array
    {
        $token = self::extractToken($request);
        $workerUuid = (string) ($request->header('X-LinkEasy-Worker') ?? $request->jsonField('worker_id', ''));

        if ($token === '' || $workerUuid === '') {
            throw HttpException::unauthorized('Worker credentials are missing.');
        }

        $worker = BrowserWorker::findByToken($token);
        if ($worker === null) {
            Logger::warn('Worker auth failed: unknown token', ['ip' => $request->ip(), 'worker' => self::mask($workerUuid)]);
            throw HttpException::unauthorized('Worker credentials are not valid.');
        }

        // Constant-time confirmation that the token belongs to the claimed worker.
        if (!hash_equals((string) $worker['worker_id'], $workerUuid)) {
            Logger::warn('Worker auth failed: token/worker mismatch', ['ip' => $request->ip()]);
            throw HttpException::forbidden('Worker identity mismatch.');
        }

        if ($mustBeEnabled && (int) $worker['is_enabled'] !== 1) {
            throw HttpException::forbidden('This worker has been disabled by an administrator.');
        }

        Database::instance()->update('browser_workers', [
            'last_heartbeat_at' => Clock::nowString(),
            'server_ok'         => 1,
        ], ['id' => (int) $worker['id']]);

        return $worker;
    }

    public static function extractToken(Request $request): string
    {
        return (string) ($request->bearerToken() ?? '');
    }

    /** Workers may only act on jobs belonging to their own tenant. */
    public static function assertSameTenant(array $worker, int $ownerUserId): void
    {
        if ((int) $worker['user_id'] !== $ownerUserId) {
            Logger::error('Worker attempted cross-tenant access', [
                'worker_id' => $worker['id'],
                'owner'     => $ownerUserId,
            ]);
            throw HttpException::forbidden('Cross-tenant access is not permitted.');
        }
    }

    /** Simple per-worker request throttle (defence in depth against token abuse). */
    public static function throttle(string $key, int $maxPerMinute = 240): void
    {
        $dir = sys_get_temp_dir() . '/linkeasy-throttle';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $bucket = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($bucket) || ($now - (int) ($bucket['start'] ?? 0)) > 60) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        @file_put_contents($file, json_encode($bucket), LOCK_EX);

        if ($bucket['count'] > $maxPerMinute) {
            throw HttpException::tooMany('Rate limit exceeded. Slow down and retry shortly.');
        }
    }

    public static function offlineThreshold(): int
    {
        return Config::int('security.worker_offline_after_s', 120);
    }

    private static function mask(string $value): string
    {
        return $value === '' ? '' : substr($value, 0, 8) . '…';
    }
}
