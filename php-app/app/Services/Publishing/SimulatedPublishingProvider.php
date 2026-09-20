<?php
declare(strict_types=1);

namespace App\Services\Publishing;

/**
 * Demo / test mode provider.
 *
 * Runs the entire pipeline — scheduling, queueing, claiming, progress,
 * verification, retries, challenges, screenshots, analytics — without touching
 * Facebook at all. Nothing is published anywhere.
 */
final class SimulatedPublishingProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'SIMULATED';
    }

    public function label(): string
    {
        return 'Simulation (nothing is published)';
    }

    public function requiresWorker(): bool
    {
        return true;   // the simulated worker still consumes the central queue
    }

    /** @return list<string> */
    public function supportedJobTypes(): array
    {
        return ['PUBLISH_POST', 'PUBLISH_IMAGE', 'PUBLISH_VIDEO', 'PUBLISH_REEL', 'VERIFY_SESSION', 'DETECT_PAGES'];
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, $this->supportedJobTypes(), true);
    }

    public function requiresRealAccount(): bool
    {
        return false;
    }

    /** @return array{ok:bool,errors:list<string>} */
    public function validateJob(array $job): array
    {
        $errors = [];
        if (empty($job['page_id'])) {
            $errors[] = 'A target Page is required.';
        }
        if (empty($job['post_id'])) {
            $errors[] = 'A post is required.';
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    public function executionPlan(array $job): array
    {
        return [
            'provider'    => $this->key(),
            'engine'      => 'simulator',
            'workflow'    => 'simulate',
            'deterministic_seconds' => [
                'prepare'   => 1,
                'upload'    => 2,
                'publish'   => 1,
                'verify'    => 1,
            ],
            'verification' => [
                'required'         => true,
                'strategy'         => 'simulated_receipt',
                'expected_caption' => mb_substr(trim((string) ($job['caption'] ?? '')), 0, 200),
            ],
            'idempotency_key' => (string) ($job['idempotency_key'] ?? ''),
            'note'            => 'Simulation only. No request is made to Facebook.',
        ];
    }
}
