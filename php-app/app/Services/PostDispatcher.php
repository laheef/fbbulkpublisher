<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Support;
use App\Models\FacebookPage;
use App\Models\Job;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Services\Publishing\ProviderFactory;

/**
 * Turns a composed post into queue work.
 *
 * Fan-out rule: one independent job per Page. Publishing to 400 Pages creates
 * 400 jobs and zero extra browser processes — the number of *sessions* is
 * governed by worker concurrency, never by the number of Pages (§29, §70).
 */
final class PostDispatcher
{
    /**
     * Validate + fan out. Returns a per-page outcome report.
     *
     * @param list<int> $pageIds
     * @param array<string,mixed> $options {
     *     when: ?string UTC 'Y-m-d H:i:s' (null = publish as soon as possible),
     *     stagger_seconds: int,
     *     priority: int,
     *     provider: ?string
     * }
     * @return array<string,mixed>
     */
    public static function dispatch(int $userId, int $postId, array $pageIds, array $options = []): array
    {
        $post = Post::find($postId);
        if ($post === null || (int) $post['user_id'] !== $userId) {
            throw HttpException::notFound('That post does not exist.');
        }

        $providerKey = strtoupper((string) ($options['provider'] ?? ProviderFactory::currentKey()));
        $provider = ProviderFactory::make($providerKey);

        $pages = FacebookPage::ownedByIds($userId, $pageIds);
        if ($pages === []) {
            throw HttpException::validation('Select at least one Page you have connected.', [
                'pages' => 'No valid Pages were selected.',
            ]);
        }

        $when = isset($options['when']) && $options['when'] !== null && $options['when'] !== ''
            ? (string) $options['when']
            : Clock::nowString();
        $stagger = max(0, (int) ($options['stagger_seconds'] ?? 0));
        $priority = max(0, min(255, (int) ($options['priority'] ?? 100)));

        $jobType = self::jobTypeFor($post);
        $report = [
            'post_id'   => $postId,
            'queued'    => 0,
            'scheduled' => 0,
            'skipped'   => [],
            'job_ids'   => [],
            'first_at'  => null,
            'last_at'   => null,
        ];

        $runIndex = self::nextRunIndex($postId);
        $offset = 0;
        $force = !empty($options['force']);

        foreach ($pages as $page) {
            $skipReason = self::skipReason($page);
            if ($skipReason !== null) {
                $report['skipped'][] = ['page' => $page['page_name'], 'reason' => $skipReason];
                continue;
            }

            // Double-submit guard: an identical dispatch while work for this
            // (post, page) is still live is a duplicate, not a second posting.
            if (!$force && self::hasLiveJob($postId, (int) $page['id'])) {
                $report['skipped'][] = ['page' => $page['page_name'], 'reason' => 'This Page already has a pending job for this post.'];
                continue;
            }

            $scheduledAt = $stagger > 0
                ? Clock::parseUtc($when)?->modify('+' . ($offset * $stagger) . ' seconds')->format('Y-m-d H:i:s')
                : $when;
            $scheduledAt ??= $when;

            // Ensure the fan-out row exists so the UI can show per-page state.
            $postPageId = self::ensurePostPage($postId, $userId, (int) $page['id']);

            $candidate = [
                'job_type' => $jobType,
                'post_id'  => $postId,
                'page_id'  => (int) $page['id'],
                'account_id' => (int) $page['account_id'],
                'media_id' => $post['media_id'] !== null ? (int) $post['media_id'] : null,
                'caption'  => $post['caption'] ?? '',
                'page_url' => $page['page_url'] ?? '',
                'profile_ref' => $page['profile_ref'] ?? '',
            ];

            $validation = $provider->validateJob($candidate);
            if (!$validation['ok']) {
                $report['skipped'][] = ['page' => $page['page_name'], 'reason' => implode(' ', $validation['errors'])];
                continue;
            }

            $result = Job::enqueue([
                'user_id'      => $userId,
                'account_id'   => (int) $page['account_id'],
                'page_id'      => (int) $page['id'],
                'post_id'      => $postId,
                'post_page_id' => $postPageId,
                'media_id'     => $post['media_id'] !== null ? (int) $post['media_id'] : null,
                'provider'     => $provider->key(),
                'job_type'     => $jobType,
                'priority'     => $priority,
                'scheduled_at' => $scheduledAt,
                'max_attempts' => (int) ($options['max_attempts'] ?? 3),
                'payload'      => [
                    'execution_plan' => $provider->executionPlan($candidate + ['idempotency_key' => '']),
                    'requested_by'   => 'dashboard',
                    'stagger_index'  => $offset,
                ],
                'salt'         => (string) ($options['salt'] ?? ''),
            ], $runIndex);

            // Re-attach the real idempotency key to the stored plan.
            $job = $result['job'];
            if ($result['created']) {
                self::storePlan((int) $job['id'], $provider, $candidate, (string) $job['idempotency_key']);
            }

            $report['job_ids'][] = (int) $job['id'];
            $report[$result['created'] ? 'queued' : 'scheduled']++;
            $report['first_at'] ??= $job['scheduled_at'];
            $report['last_at'] = $job['scheduled_at'];

            FacebookPage::refreshCounters((int) $page['id']);
            $offset++;
        }

        if ($report['queued'] + $report['scheduled'] > 0) {
            Post::setStatus($postId, Clock::isPast($when) ? 'QUEUED' : 'SCHEDULED');
            Post::refreshPageCount($postId);
        }

        Logger::info('Post dispatched', [
            'post_id'  => $postId,
            'pages'    => count($pages),
            'queued'   => $report['queued'],
            'skipped'  => count($report['skipped']),
            'user_id'  => $userId,
        ]);

        return $report;
    }

    /**
     * Create a recurring schedule entry. The cron expands it into jobs at each
     * run, reusing the same idempotency key space so a re-run cannot duplicate.
     *
     * @param array<string,mixed> $options
     */
    public static function schedule(int $userId, int $postId, array $options): array
    {
        $post = Post::find($postId);
        if ($post === null || (int) $post['user_id'] !== $userId) {
            throw HttpException::notFound('That post does not exist.');
        }

        $timezone = (string) ($options['timezone'] ?? 'UTC');
        $local = (string) ($options['scheduled_at_local'] ?? '');
        $utc = Clock::localToUtc($local, $timezone);

        if (Clock::parseUtc($utc) === null) {
            throw HttpException::validation('The scheduled time could not be interpreted.');
        }

        $recurrence = in_array(($options['recurrence'] ?? 'NONE'), ScheduledPost::RECURRENCES, true)
            ? (string) $options['recurrence']
            : 'NONE';
        $interval = isset($options['interval_value']) ? max(0, (int) $options['interval_value']) : null;

        if ($recurrence !== 'NONE' && ($interval === null || $interval < 1)) {
            throw HttpException::validation('Recurring schedules need an interval greater than zero.', [
                'interval_value' => 'Enter the number of minutes or hours between runs.',
            ]);
        }

        $id = ScheduledPost::create([
            'user_id'         => $userId,
            'post_id'         => $postId,
            'page_id'         => isset($options['page_id']) && (int) $options['page_id'] > 0 ? (int) $options['page_id'] : null,
            'timezone'        => $timezone,
            'scheduled_at'    => $utc,
            'recurrence'      => $recurrence,
            'interval_value'  => $interval,
            'stagger_seconds' => max(0, (int) ($options['stagger_seconds'] ?? 0)),
            'next_run_at'     => $utc,
            'max_runs'        => isset($options['max_runs']) && (int) $options['max_runs'] > 0 ? (int) $options['max_runs'] : null,
            'status'          => 'SCHEDULED',
        ]) ?? [];

        Post::setStatus($postId, 'SCHEDULED');
        \App\Models\ActivityLog::record($userId, 'post.scheduled', 'post', $postId, null, 'user', $userId, [
            'scheduled_at_utc' => $utc,
            'timezone'         => $timezone,
            'recurrence'       => $recurrence,
        ]);

        return $id;
    }

    /** Job type derived from the post's content, not from user input. */
    public static function jobTypeFor(array $post): string
    {
        return match ((string) $post['kind']) {
            'IMAGE' => 'PUBLISH_IMAGE',
            'VIDEO' => 'PUBLISH_VIDEO',
            'REEL'  => 'PUBLISH_REEL',
            default => 'PUBLISH_POST',
        };
    }

    /** Monotonic run counter so a deliberately repeated schedule cannot collide. */
    private static function nextRunIndex(int $postId): int
    {
        return (int) \App\Core\Database::instance()->scalar(
            'SELECT COUNT(*) + 1 FROM jobs WHERE post_id = ?',
            [$postId]
        );
    }

    /**
     * True when this (post, page) pair already has work that has not reached a
     * terminal state — the guard that stops a double-click from publishing twice.
     */
    private static function hasLiveJob(int $postId, int $pageId): bool
    {
        $count = (int) \App\Core\Database::instance()->scalar(
            "SELECT COUNT(*) FROM jobs
             WHERE post_id = ? AND page_id = ?
               AND status NOT IN ('PUBLISHED','FAILED','CANCELLED')",
            [$postId, $pageId]
        );
        return $count > 0;
    }

    private static function ensurePostPage(int $postId, int $userId, int $pageId): ?int
    {
        $existing = \App\Core\Database::instance()->first(
            'SELECT id FROM post_pages WHERE post_id = ? AND page_id = ? LIMIT 1',
            [$postId, $pageId]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Post::attachPages($postId, $userId, [$pageId]);
        $created = \App\Core\Database::instance()->first(
            'SELECT id FROM post_pages WHERE post_id = ? AND page_id = ? LIMIT 1',
            [$postId, $pageId]
        );
        return $created === null ? null : (int) $created['id'];
    }

    /** @param array<string,mixed> $candidate */
    private static function storePlan(int $jobId, $provider, array $candidate, string $idempotencyKey): void
    {
        \App\Core\Database::instance()->update('jobs', [
            'payload' => Support::jsonEncode([
                'execution_plan' => $provider->executionPlan($candidate + ['idempotency_key' => $idempotencyKey]),
                'idempotency_key' => $idempotencyKey,
                'requested_by'   => 'dashboard',
            ]),
        ], ['id' => $jobId]);
    }

    /** Why a Page cannot receive work right now (null = it can). @return ?string */
    private static function skipReason(array $page): ?string
    {
        if ((string) $page['status'] === 'DISABLED') {
            return 'Page is disabled in LinkEasy.';
        }
        if ((string) $page['status'] === 'AUTH_REQUIRED') {
            return 'The connected account needs to sign in to Facebook again.';
        }
        if ((string) $page['status'] === 'CHALLENGE_REQUIRED') {
            return 'Facebook is asking for a security verification on this account.';
        }
        if ((string) $page['account_status'] !== 'CONNECTED') {
            return 'The connected Facebook account is not online.';
        }
        if (empty($page['worker_id'])) {
            return 'No Windows worker is currently attached to this account.';
        }
        return null;
    }
}
