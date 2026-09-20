<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Support;
use App\Services\JobFailureCodes;

/**
 * The job table is the single source of truth for publishing work.
 *
 * Claiming is atomic and idempotent:
 *   - every job carries a unique idempotency_key
 *   - workers claim with an UPDATE ... WHERE status='QUEUED' guard plus a lease
 *   - a crashed worker's lease expires and the job is safely requeued
 *   - retrying never creates a second row, so Facebook can never receive a
 *     duplicate publication for the same (post, page, run).
 */
final class Job extends Model
{
    protected static string $table = 'jobs';
    protected static array $fillable = [
        'uuid', 'idempotency_key', 'user_id', 'account_id', 'page_id', 'post_id', 'post_page_id',
        'media_id', 'provider', 'job_type', 'priority', 'scheduled_at', 'status', 'stage',
        'progress_pct', 'attempts', 'max_attempts', 'next_attempt_at', 'worker_id', 'locked_at',
        'lease_expires_at', 'started_at', 'completed_at', 'result_url', 'error_code',
        'error_message', 'screenshot_path', 'trace_path', 'payload',
    ];

    public const TERMINAL = ['PUBLISHED', 'FAILED', 'CANCELLED'];
    public const ACTIVE = ['CLAIMED', 'PROCESSING', 'UPLOADING', 'PUBLISHING', 'VERIFYING'];
    public const PENDING = ['SCHEDULED', 'QUEUED', 'RETRYING'];
    public const USER_ACTION = ['USER_ACTION_REQUIRED', 'ACCOUNT_REAUTH_REQUIRED'];
    public const STATES = [
        'DRAFT', 'SCHEDULED', 'QUEUED', 'CLAIMED', 'PROCESSING', 'UPLOADING', 'PUBLISHING', 'VERIFYING',
        'PUBLISHED', 'FAILED', 'RETRYING', 'PAUSED', 'USER_ACTION_REQUIRED',
        'ACCOUNT_REAUTH_REQUIRED', 'CANCELLED',
    ];

    /**
     * Create a job for (post, page). Returns the existing job when the
     * idempotency key already exists — the cornerstone of duplicate-free retry.
     *
     * @param array<string,mixed> $attributes
     * @return array{job:array<string,mixed>,created:bool}
     */
    public static function enqueue(array $attributes, int $runIndex = 0, ?string $salt = null): array
    {
        $postId = (int) $attributes['post_id'];
        $pageId = (int) $attributes['page_id'];
        $salt ??= (string) ($attributes['salt'] ?? '');

        $key = Support::idempotencyKey($postId, $pageId, $runIndex, $salt);

        $existing = static::db()->first('SELECT * FROM jobs WHERE idempotency_key = ? LIMIT 1', [$key]);
        if ($existing !== null) {
            return ['job' => $existing, 'created' => false];
        }

        $scheduledAt = (string) ($attributes['scheduled_at'] ?? Clock::nowString());
        $status = Clock::isPast($scheduledAt) ? 'QUEUED' : 'SCHEDULED';

        // Every publication job needs its post × page row, because that row is
        // what the post's status is rolled up from. Callers normally pass it in
        // (PostDispatcher does); anything else gets it attached here rather than
        // silently producing a post that can never reach PUBLISHED.
        $postPageId = (int) ($attributes['post_page_id'] ?? 0);

        if ($postPageId === 0) {
            $existing = static::db()->first(
                'SELECT id FROM post_pages WHERE post_id = ? AND page_id = ? LIMIT 1',
                [$postId, $pageId]
            );

            $postPageId = $existing !== null
                ? (int) $existing['id']
                : static::db()->insert('post_pages', [
                    'post_id'    => $postId,
                    'page_id'    => $pageId,
                    'user_id'    => (int) $attributes['user_id'],
                    'status'     => $status === 'QUEUED' ? 'QUEUED' : 'PENDING',
                    'created_at' => Clock::nowString(),
                    'updated_at' => Clock::nowString(),
                ]);
        }

        $payload = [
            'uuid'             => Support::uuid4(),
            'idempotency_key'  => $key,
            'user_id'          => (int) $attributes['user_id'],
            'account_id'       => (int) $attributes['account_id'],
            'page_id'          => $pageId,
            'post_id'          => $postId,
            'post_page_id'     => $attributes['post_page_id'] ?? null,
            'media_id'         => $attributes['media_id'] ?? null,
            'provider'         => (string) ($attributes['provider'] ?? 'BROWSER'),
            'job_type'         => (string) ($attributes['job_type'] ?? 'PUBLISH_POST'),
            'priority'         => (int) ($attributes['priority'] ?? 100),
            'scheduled_at'     => $scheduledAt,
            'status'           => $status,
            'stage'            => 'enqueued',
            'max_attempts'     => (int) ($attributes['max_attempts'] ?? Config::int('queue.max_attempts', 3)),
            'next_attempt_at'  => $scheduledAt,
            'payload'          => json_encode($attributes['payload'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
            'post_page_id'     => $postPageId,
        ];

        try {
            $id = static::db()->insert('jobs', $payload);
        } catch (\PDOException $e) {
            // Concurrent insert of the same idempotency key: return the winner.
            $existing = static::db()->first('SELECT * FROM jobs WHERE idempotency_key = ? LIMIT 1', [$key]);
            if ($existing !== null) {
                return ['job' => $existing, 'created' => false];
            }
            throw $e;
        }

        JobEvent::record($id, null, $status, 'enqueued', 'Job queued by the scheduler.', 'system');

        if ($postPageId > 0) {
            static::db()->update('post_pages', [
                'job_id' => $id,
                'status' => $status === 'QUEUED' ? 'QUEUED' : 'PENDING',
            ], ['id' => $postPageId]);
        }

        return ['job' => static::find($id) ?? [], 'created' => true];
    }

    /**
     * Atomically claim up to $limit due jobs for a worker.
     *
     * MySQL uses SELECT ... FOR UPDATE SKIP LOCKED so multiple workers can
     * claim concurrently without ever double-claiming. SQLite serialises
     * writers, so a transaction is sufficient there.
     *
     * @return list<array<string,mixed>>
     */
    public static function claimForWorker(array $worker, int $limit): array
    {
        $db = static::db();
        $now = Clock::nowString();
        $leaseSeconds = Config::int('queue.lease_seconds', 900);
        $leaseExpiry = Clock::addSeconds($leaseSeconds);
        $claimed = [];

        $eligibility = "(status = 'QUEUED' OR (status = 'RETRYING' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)))
                        AND scheduled_at <= ?
                        AND (lease_expires_at IS NULL OR lease_expires_at < ?)";

        $db->beginTransaction();
        try {
            $rows = $db->select(
                "SELECT * FROM jobs WHERE user_id = ? AND {$eligibility}
                 ORDER BY priority ASC, scheduled_at ASC, id ASC
                 LIMIT " . (int) $limit . $db->forUpdateSkipLocked(),
                [(int) $worker['user_id'], $now, $now, $now]
            );

            foreach ($rows as $row) {
                $affected = $db->query(
                    "UPDATE jobs SET status = 'CLAIMED', worker_id = ?, locked_at = ?, lease_expires_at = ?,
                            started_at = COALESCE(started_at, ?), attempts = attempts + 1,
                            stage = 'claimed', progress_pct = 2, error_message = NULL
                     WHERE id = ? AND (status = 'QUEUED' OR status = 'RETRYING')",
                    [(int) $worker['id'], $now, $leaseExpiry, $now, (int) $row['id']]
                )->rowCount();

                if ($affected === 1) {
                    JobEvent::record((int) $row['id'], (string) $row['status'], 'CLAIMED', 'claimed',
                        'Claimed by worker ' . $worker['name'], 'worker');
                    if (!empty($row['post_page_id'])) {
                        $db->update('post_pages', ['status' => 'CLAIMED'], ['id' => (int) $row['post_page_id']]);
                    }
                    $claimed[] = static::find((int) $row['id']) ?? $row;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $claimed;
    }

    public static function heartbeatLease(int $jobId, int $workerId, ?string $stage = null, ?int $progress = null): bool
    {
        $update = [
            'lease_expires_at' => Clock::addSeconds(Config::int('queue.lease_seconds', 900)),
        ];
        if ($stage !== null) {
            $update['stage'] = mb_substr($stage, 0, 64);
        }
        if ($progress !== null) {
            $update['progress_pct'] = max(0, min(100, $progress));
        }

        return static::db()->query(
            'UPDATE jobs SET ' . implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($update)))
            . ' WHERE id = :id AND worker_id = :worker_id AND status IN (\'CLAIMED\',\'PROCESSING\',\'UPLOADING\',\'PUBLISHING\',\'VERIFYING\')',
            $update + ['id' => $jobId, 'worker_id' => $workerId]
        )->rowCount() === 1;
    }

    /**
     * Record a progress transition. Only forward transitions from a non
     * terminal state are accepted.
     *
     * @param array<string,mixed> $data
     */
    public static function progress(int $jobId, array $data): bool
    {
        $status = (string) ($data['status'] ?? 'PROCESSING');
        if (!in_array($status, self::STATES, true)) {
            return false;
        }

        $current = static::find($jobId);
        if ($current === null || in_array((string) $current['status'], self::TERMINAL, true)) {
            return false;
        }

        $update = [
            'status'           => $status,
            'stage'            => isset($data['stage']) ? mb_substr((string) $data['stage'], 0, 64) : $current['stage'],
            'progress_pct'     => isset($data['progress_pct']) ? max(0, min(100, (int) $data['progress_pct'])) : (int) $current['progress_pct'],
            'lease_expires_at' => Clock::addSeconds(Config::int('queue.lease_seconds', 900)),
        ];
        if (isset($data['message'])) {
            $update['error_message'] = null; // a progress ping clears a stale message
        }

        static::db()->update('jobs', $update, ['id' => $jobId]);

        if (!empty($current['post_page_id'])) {
            static::db()->update('post_pages', ['status' => $status], ['id' => (int) $current['post_page_id']]);
        }

        JobEvent::record($jobId, (string) $current['status'], $status, (string) ($data['stage'] ?? null),
            isset($data['message']) ? (string) $data['message'] : null, (string) ($data['actor'] ?? 'worker'));

        return true;
    }

    /** @param array<string,mixed> $result */
    public static function complete(int $jobId, array $result): bool
    {
        $job = static::find($jobId);
        if ($job === null) {
            return false;
        }

        // Idempotent completion: reporting success twice is harmless.
        if ((string) $job['status'] === 'PUBLISHED') {
            return true;
        }

        $now = Clock::nowString();
        $url = mb_substr((string) ($result['result_url'] ?? ''), 0, 1000);

        // A publication is only successful with evidence: either a link to the
        // post, or an explicit verification flag. Anything else is escalated to
        // a human rather than reported as a success (prompt §22). The controller
        // enforces this too; the model must not be a weaker path to PUBLISHED.
        $isPublication = str_starts_with((string) $job['job_type'], 'PUBLISH');

        if ($isPublication && $url === '' && empty($result['verified'])) {
            throw HttpException::validation(
                'A published post must include its URL or be explicitly verified. '
                . 'If you cannot confirm it, report PUBLISH_VERIFICATION_REQUIRED instead.'
            );
        }

        static::db()->update('jobs', [
            'status'          => 'PUBLISHED',
            'stage'           => 'completed',
            'progress_pct'    => 100,
            'completed_at'    => $now,
            'result_url'      => $url !== '' ? $url : null,
            'error_code'      => null,
            'error_message'   => null,
            'locked_at'       => null,
            'lease_expires_at' => null,
            'screenshot_path' => isset($result['screenshot_path']) ? mb_substr((string) $result['screenshot_path'], 0, 500) : null,
            'trace_path'      => isset($result['trace_path']) ? mb_substr((string) $result['trace_path'], 0, 500) : null,
            'payload'         => json_encode($result['meta'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
        ], ['id' => $jobId]);

        if (!empty($job['post_page_id'])) {
            static::db()->update('post_pages', [
                'status'        => 'PUBLISHED',
                'published_url' => $url !== '' ? $url : null,
                'published_at'  => $now,
            ], ['id' => (int) $job['post_page_id']]);
        }

        FacebookPage::markPublished((int) $job['page_id'], $url, $now);
        FacebookPage::refreshCounters((int) $job['page_id']);
        Post::rollUpStatus((int) $job['post_id']);
        JobEvent::record($jobId, (string) $job['status'], 'PUBLISHED', 'completed', 'Publication verified.', 'worker');

        return true;
    }

    /**
     * @param array<string,mixed> $failure
     * @return array{status:string,retry_at:?string,attempts:int}
     */
    public static function fail(int $jobId, array $failure): array
    {
        $job = static::find($jobId);
        if ($job === null) {
            return ['status' => 'FAILED', 'retry_at' => null, 'attempts' => 0];
        }

        $code = mb_substr((string) ($failure['error_code'] ?? 'UNKNOWN_ERROR'), 0, 64);
        $message = Support::redact((string) ($failure['error_message'] ?? 'The job failed.'));
        $message = mb_substr($message, 0, 1000);

        // These states are terminal for automation and need a human.
        if (!empty($failure['user_action_required'])) {
            $state = $code === 'ACCOUNT_REAUTH_REQUIRED' ? 'ACCOUNT_REAUTH_REQUIRED' : 'USER_ACTION_REQUIRED';
            static::db()->update('jobs', [
                'status'           => $state,
                'stage'            => 'waiting_for_user',
                'error_code'       => $code,
                'error_message'    => $message,
                'worker_id'        => null,
                'locked_at'        => null,
                'lease_expires_at' => null,
                'screenshot_path'  => isset($failure['screenshot_path']) ? mb_substr((string) $failure['screenshot_path'], 0, 500) : null,
                'trace_path'       => isset($failure['trace_path']) ? mb_substr((string) $failure['trace_path'], 0, 500) : null,
            ], ['id' => $jobId]);

            if (!empty($job['post_page_id'])) {
                static::db()->update('post_pages', ['status' => $state], ['id' => (int) $job['post_page_id']]);
            }

            // A challenge reported by the worker usually applies to the whole
            // signed-in session, so the account (and its Pages) is marked until
            // the operator clears it. Callers may scope it to a single job
            // instead by passing mark_account = false.
            $markAccount = (bool) ($failure['mark_account'] ?? true);

            if ($markAccount && (int) $job['account_id'] > 0 && $code === 'ACCOUNT_REAUTH_REQUIRED') {
                FacebookAccount::setStatus((int) $job['account_id'], 'AUTH_REQUIRED', $code, $message);
            } elseif ($markAccount && (int) $job['account_id'] > 0 && in_array($code, ['CAPTCHA_DETECTED', 'SECURITY_CHALLENGE', 'CHECKPOINT', '2FA_REQUIRED', 'IDENTITY_VERIFICATION'], true)) {
                FacebookAccount::setStatus((int) $job['account_id'], 'CHALLENGE_REQUIRED', $code, $message);
            }

            JobEvent::record($jobId, (string) $job['status'], $state, 'user_action_required', $message, 'worker');
            Post::rollUpStatus((int) $job['post_id']);
            FacebookPage::refreshCounters((int) $job['page_id']);

            Notification::push((int) $job['user_id'], 'action_required',
                'Your action is required',
                $message !== '' ? $message : 'Facebook needs your attention before publishing can continue.',
                '/queue?job=' . $jobId, $jobId);

            return ['status' => $state, 'retry_at' => null, 'attempts' => (int) $job['attempts']];
        }

        $attempts = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];

        // The worker's "retryable" hint is advisory only. A permanent code
        // (interface change, missing Page, invalid media, verification required…)
        // must never be retried, and neither must a challenge that needs a human.
        $retryable = !empty($failure['retryable'])
            && $attempts < $maxAttempts
            && !JobFailureCodes::isPermanent($code)
            && !JobFailureCodes::requiresUserAction($code);

        if ($retryable) {
            $backoff = (array) Config::get('queue.backoff_schedule', [60, 300, 900]);
            $delay = (int) ($backoff[min($attempts - 1, count($backoff) - 1)] ?? 300);
            $retryAt = Clock::addSeconds($delay);

            static::db()->update('jobs', [
                'status'           => 'RETRYING',
                'stage'            => 'retry_scheduled',
                'error_code'       => $code,
                'error_message'    => $message,
                'next_attempt_at'  => $retryAt,
                'worker_id'        => null,
                'locked_at'        => null,
                'lease_expires_at' => null,
                'screenshot_path'  => isset($failure['screenshot_path']) ? mb_substr((string) $failure['screenshot_path'], 0, 500) : null,
                'trace_path'       => isset($failure['trace_path']) ? mb_substr((string) $failure['trace_path'], 0, 500) : null,
            ], ['id' => $jobId]);

            if (!empty($job['post_page_id'])) {
                static::db()->update('post_pages', ['status' => 'RETRYING'], ['id' => (int) $job['post_page_id']]);
            }

            JobEvent::record($jobId, (string) $job['status'], 'RETRYING', 'retry_scheduled',
                "Attempt {$attempts}/{$maxAttempts}: {$message}", 'worker');
            Post::rollUpStatus((int) $job['post_id']);

            return ['status' => 'RETRYING', 'retry_at' => $retryAt, 'attempts' => $attempts];
        }

        static::db()->update('jobs', [
            'status'           => 'FAILED',
            'stage'            => 'failed',
            'error_code'       => $code,
            'error_message'    => $message,
            'completed_at'     => Clock::nowString(),
            'worker_id'        => null,
            'locked_at'        => null,
            'lease_expires_at' => null,
            'screenshot_path'  => isset($failure['screenshot_path']) ? mb_substr((string) $failure['screenshot_path'], 0, 500) : null,
            'trace_path'       => isset($failure['trace_path']) ? mb_substr((string) $failure['trace_path'], 0, 500) : null,
        ], ['id' => $jobId]);

        if (!empty($job['post_page_id'])) {
            static::db()->update('post_pages', ['status' => 'FAILED'], ['id' => (int) $job['post_page_id']]);
        }

        JobEvent::record($jobId, (string) $job['status'], 'FAILED', 'failed', $message, 'worker');
        Post::rollUpStatus((int) $job['post_id']);
        FacebookPage::refreshCounters((int) $job['page_id']);

        Notification::push((int) $job['user_id'], 'error', 'Publication failed',
            "{$code}: {$message}", '/queue?job=' . $jobId, $jobId);

        return ['status' => 'FAILED', 'retry_at' => null, 'attempts' => $attempts];
    }

    /** Manually requeue a failed / user-action job. Always reuses the same row. */
    public static function requeue(int $jobId, ?string $scheduledAt = null): bool
    {
        $job = static::find($jobId);
        if ($job === null) {
            return false;
        }
        if (!in_array((string) $job['status'], array_merge(['FAILED'], self::USER_ACTION, ['CANCELLED', 'PAUSED']), true)) {
            return false;
        }

        $at = $scheduledAt ?? Clock::nowString();
        static::db()->update('jobs', [
            'status'          => Clock::isPast($at) ? 'QUEUED' : 'SCHEDULED',
            'scheduled_at'    => $at,
            'next_attempt_at' => $at,
            'attempts'        => 0,
            'stage'           => 'requeued',
            'progress_pct'    => 0,
            'error_code'      => null,
            'error_message'   => null,
            'completed_at'    => null,
            'locked_at'       => null,
            'lease_expires_at' => null,
            'worker_id'       => null,
        ], ['id' => $jobId]);

        if (!empty($job['post_page_id'])) {
            static::db()->update('post_pages', ['status' => 'QUEUED'], ['id' => (int) $job['post_page_id']]);
        }

        JobEvent::record($jobId, (string) $job['status'], 'QUEUED', 'requeued', 'Requeued by operator.', 'user');
        Post::rollUpStatus((int) $job['post_id']);

        return true;
    }

    public static function cancel(int $jobId, string $actor = 'user'): bool
    {
        $job = static::find($jobId);
        if ($job === null || in_array((string) $job['status'], self::TERMINAL, true)) {
            return false;
        }

        static::db()->update('jobs', [
            'status'           => 'CANCELLED',
            'stage'            => 'cancelled',
            'completed_at'     => Clock::nowString(),
            'worker_id'        => null,
            'locked_at'        => null,
            'lease_expires_at' => null,
        ], ['id' => $jobId]);

        if (!empty($job['post_page_id'])) {
            static::db()->update('post_pages', ['status' => 'CANCELLED'], ['id' => (int) $job['post_page_id']]);
        }

        JobEvent::record($jobId, (string) $job['status'], 'CANCELLED', 'cancelled', 'Cancelled by operator.', $actor);
        Post::rollUpStatus((int) $job['post_id']);

        return true;
    }

    public static function pause(int $jobId): bool
    {
        $job = static::find($jobId);
        if ($job === null || !in_array((string) $job['status'], self::PENDING, true)) {
            return false;
        }
        static::db()->update('jobs', ['status' => 'PAUSED'], ['id' => $jobId]);
        JobEvent::record($jobId, (string) $job['status'], 'PAUSED', 'paused', 'Paused by operator.', 'user');
        return true;
    }

    public static function resume(int $jobId): bool
    {
        $job = static::find($jobId);
        if ($job === null || (string) $job['status'] !== 'PAUSED') {
            return false;
        }
        $at = Clock::nowString();
        static::db()->update('jobs', ['status' => 'QUEUED', 'scheduled_at' => $at, 'next_attempt_at' => $at], ['id' => $jobId]);
        JobEvent::record($jobId, 'PAUSED', 'QUEUED', 'resumed', 'Resumed by operator.', 'user');
        return true;
    }

    /** Promote SCHEDULED jobs whose time has arrived into the claimable queue. */
    public static function releaseDueScheduled(int $limit = 200): int
    {
        return static::db()->query(
            "UPDATE jobs SET status = 'QUEUED', stage = 'queued', next_attempt_at = COALESCE(next_attempt_at, scheduled_at)
             WHERE status = 'SCHEDULED' AND scheduled_at <= ?
             LIMIT " . (int) $limit,
            [Clock::nowString()]
        )->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT j.*, f.page_name, f.page_id AS fb_page_id, a.label AS account_label,
                       w.name AS worker_name, p.caption, p.kind AS post_kind, m.kind AS media_kind
                FROM jobs j
                JOIN facebook_pages f ON f.id = j.page_id
                JOIN facebook_accounts a ON a.id = j.account_id
                JOIN posts p ON p.id = j.post_id
                LEFT JOIN browser_workers w ON w.id = j.worker_id
                LEFT JOIN media m ON m.id = j.media_id
                WHERE j.user_id = ?";
        $params = [$userId];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            if ($status === 'ACTIVE') {
                $sql .= " AND j.status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')";
            } elseif ($status === 'PENDING') {
                $sql .= " AND j.status IN ('SCHEDULED','QUEUED','RETRYING')";
            } elseif ($status === 'ACTION') {
                $sql .= " AND j.status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED')";
            } elseif (in_array($status, self::STATES, true)) {
                $sql .= ' AND j.status = ?';
                $params[] = $status;
            }
        }
        if (!empty($filters['page_id'])) {
            $sql .= ' AND j.page_id = ?';
            $params[] = (int) $filters['page_id'];
        }
        if (!empty($filters['worker_id'])) {
            $sql .= ' AND j.worker_id = ?';
            $params[] = (int) $filters['worker_id'];
        }

        $sql .= ' ORDER BY j.scheduled_at ASC, j.id ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return static::db()->select($sql, $params);
    }

    /** @return array<string,mixed>|null Job with everything needed by a worker to execute. */
    public static function forWorker(int $jobId, int $workerId): ?array
    {
        $job = static::db()->first(
            'SELECT j.*, p.caption, p.hashtags, p.link_url, p.title, p.kind AS post_kind, p.settings AS post_settings,
                    f.page_name, f.page_id AS fb_page_id, f.page_url, m.kind AS media_kind,
                    m.relative_path, m.original_name, m.mime_type, m.size_bytes, m.strategy, m.external_url,
                    a.profile_ref, a.label AS account_label
             FROM jobs j
             JOIN posts p ON p.id = j.post_id
             JOIN facebook_pages f ON f.id = j.page_id
             JOIN facebook_accounts a ON a.id = j.account_id
             LEFT JOIN media m ON m.id = j.media_id
             WHERE j.id = ? AND j.worker_id = ? LIMIT 1',
            [$jobId, $workerId]
        );
        if ($job === null) {
            return null;
        }

        // The worker receives a media URL, never a filesystem path outside its
        // own storage. Local/EXTERNAL strategies are resolved by the worker.
        $job['media_url'] = null;
        if (!empty($job['media_id']) && (string) ($job['strategy'] ?? 'SERVER') === 'SERVER') {
            $job['media_url'] = '/worker/jobs/' . (int) $job['id'] . '/media';
        }
        if ((string) ($job['strategy'] ?? '') === 'EXTERNAL' && !empty($job['external_url'])) {
            $job['media_url'] = (string) $job['external_url'];
        }

        return $job;
    }

    /** @return array<string,mixed> */
    public static function summaryForUser(int $userId): array
    {
        $row = static::db()->first(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('SCHEDULED','QUEUED') THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING') THEN 1 ELSE 0 END) AS publishing,
                SUM(CASE WHEN status = 'PUBLISHED' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'RETRYING' THEN 1 ELSE 0 END) AS retrying,
                SUM(CASE WHEN status IN ('USER_ACTION_REQUIRED','ACCOUNT_REAUTH_REQUIRED') THEN 1 ELSE 0 END) AS action_required,
                SUM(CASE WHEN status = 'PAUSED' THEN 1 ELSE 0 END) AS paused_noop,
                SUM(CASE WHEN status = 'PUBLISHED' AND completed_at >= ? THEN 1 ELSE 0 END) AS published_today
             FROM jobs WHERE user_id = ?",
            [Clock::now()->modify('midnight')->format('Y-m-d H:i:s'), $userId]
        ) ?? [];

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'queued'          => (int) ($row['queued'] ?? 0),
            'publishing'      => (int) ($row['publishing'] ?? 0),
            'published'       => (int) ($row['published'] ?? 0),
            'failed'          => (int) ($row['failed'] ?? 0),
            'retrying'        => (int) ($row['retrying'] ?? 0),
            'action_required' => (int) ($row['action_required'] ?? 0),
            'published_today' => (int) ($row['published_today'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recoveryCandidates(int $limit = 50): array
    {
        return static::db()->select(
            "SELECT * FROM jobs WHERE status = 'RETRYING' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY next_attempt_at ASC LIMIT " . (int) $limit,
            [Clock::nowString()]
        );
    }
}
