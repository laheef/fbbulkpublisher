<?php
declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Media;
use App\Models\Post;

/**
 * Current implementation: publishing is performed by the Windows worker
 * driving an authorised, user-authenticated Facebook browser session.
 *
 * This class contains no selectors and no browser code — it only validates the
 * job and describes the execution plan the worker will follow.
 */
final class BrowserPublishingProvider implements ProviderInterface
{
    public const JOB_TYPES = [
        'PUBLISH_POST', 'PUBLISH_IMAGE', 'PUBLISH_VIDEO', 'PUBLISH_REEL', 'VERIFY_SESSION', 'DETECT_PAGES',
    ];

    public function key(): string
    {
        return 'BROWSER';
    }

    public function label(): string
    {
        return 'Authorised browser session (Windows worker)';
    }

    public function requiresWorker(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function supportedJobTypes(): array
    {
        return self::JOB_TYPES;
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, self::JOB_TYPES, true);
    }

    /** @return array{ok:bool,errors:list<string>} */
    public function validateJob(array $job): array
    {
        $errors = [];

        $jobType = (string) ($job['job_type'] ?? 'PUBLISH_POST');
        if (!$this->supports($jobType)) {
            $errors[] = "Unsupported job type: {$jobType}";
        }

        if (empty($job['page_id'])) {
            $errors[] = 'A target Facebook Page is required.';
        }
        if (empty($job['account_id'])) {
            $errors[] = 'A connected Facebook account is required.';
        }

        // Media-dependent job types must have usable media attached.
        if (in_array($jobType, ['PUBLISH_IMAGE', 'PUBLISH_VIDEO', 'PUBLISH_REEL'], true)) {
            $media = !empty($job['media_id']) ? Media::find((int) $job['media_id']) : null;
            if ($media === null) {
                $errors[] = 'The job references media that no longer exists.';
            } else {
                $expected = $jobType === 'PUBLISH_IMAGE' ? 'IMAGE' : 'VIDEO';
                if ((string) $media['kind'] !== $expected) {
                    $errors[] = "This job needs {$expected} media but the attached file is {$media['kind']}.";
                }
                if ((string) ($media['probe_status'] ?? '') === 'FAILED') {
                    $errors[] = 'FFmpeg could not read the attached media file; please re-upload it.';
                }
                if (in_array((string) ($media['strategy'] ?? 'SERVER'), ['SERVER'], true)) {
                    $path = Media::absolutePath($media);
                    if (!is_file($path)) {
                        $errors[] = 'The attached media file is missing from storage.';
                    }
                }
            }
        }

        // A browser session cannot do anything with an empty caption and no media.
        $post = !empty($job['post_id']) ? Post::find((int) $job['post_id']) : null;
        if ($post !== null) {
            $hasText = trim((string) ($post['caption'] ?? '')) !== ''
                || trim((string) ($post['hashtags'] ?? '')) !== ''
                || trim((string) ($post['link_url'] ?? '')) !== '';
            if (!$hasText && empty($job['media_id'])) {
                $errors[] = 'The post has neither text nor media to publish.';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    public function executionPlan(array $job): array
    {
        $jobType = (string) ($job['job_type'] ?? 'PUBLISH_POST');

        return [
            'provider'        => $this->key(),
            'engine'          => 'playwright-chromium',
            'profile_ref'     => (string) ($job['profile_ref'] ?? ''),
            'target'          => [
                'page_ref' => (string) ($job['page_id'] ?? ''),
                'page_url' => (string) ($job['page_url'] ?? ''),
            ],
            'workflow'        => match ($jobType) {
                'PUBLISH_IMAGE'  => 'image',
                'PUBLISH_VIDEO'  => 'video',
                'PUBLISH_REEL'   => 'reel',
                'VERIFY_SESSION' => 'session',
                'DETECT_PAGES'   => 'pages',
                default          => 'post',
            },
            'media'           => [
                'required'   => in_array($jobType, ['PUBLISH_IMAGE', 'PUBLISH_VIDEO', 'PUBLISH_REEL'], true),
                'kind'       => $job['media_kind'] ?? null,
                'download'   => !empty($job['media_url']),
                'size_bytes' => isset($job['size_bytes']) ? (int) $job['size_bytes'] : null,
            ],
            'verification'    => [
                'required'          => true,
                'strategy'          => 'publish_then_locate',
                'expected_caption'  => mb_substr(trim((string) ($job['caption'] ?? '')), 0, 200),
                'max_wait_seconds'  => 180,
            ],
            'idempotency_key' => (string) ($job['idempotency_key'] ?? ''),
            'trace_mode'      => 'failures_only',
        ];
    }
}
