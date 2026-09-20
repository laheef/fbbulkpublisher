<?php
declare(strict_types=1);

namespace App\Services\Publishing;

use App\Core\HttpException;

/**
 * Placeholder for a future official Facebook Graph API provider.
 *
 * It exists now so the scheduler, queue and UI are written against an
 * abstraction rather than against browser automation. Enabling it will require
 * an app review, page access tokens, and the appropriate permissions — none of
 * which are implemented here. It fails loudly rather than silently degrading.
 */
final class OfficialApiPublishingProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'OFFICIAL_API';
    }

    public function label(): string
    {
        return 'Official Facebook Graph API (not yet enabled)';
    }

    public function requiresWorker(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function supportedJobTypes(): array
    {
        return ['PUBLISH_POST', 'PUBLISH_IMAGE', 'PUBLISH_VIDEO', 'PUBLISH_REEL'];
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, $this->supportedJobTypes(), true);
    }

    /** @return array{ok:bool,errors:list<string>} */
    public function validateJob(array $job): array
    {
        return [
            'ok'     => false,
            'errors' => ['The official Graph API provider is not configured on this installation.'],
        ];
    }

    /** @return array<string,mixed> */
    public function executionPlan(array $job): array
    {
        throw new HttpException(501, 'The official Graph API provider is not implemented in this build.');
    }
}
