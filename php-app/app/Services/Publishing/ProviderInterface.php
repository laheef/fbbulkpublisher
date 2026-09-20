<?php
declare(strict_types=1);

namespace App\Services\Publishing;

/**
 * The scheduler never talks to a network. It hands work to a provider through
 * this interface, which is what lets a future official Graph API provider slot
 * in without touching the queue, the scheduler, or the UI (prompt §58).
 *
 * Implementations:
 *   - BrowserPublishingProvider   (current: the Windows Playwright worker)
 *   - SimulatedPublishingProvider (test/demo mode, no network at all)
 *   - OfficialApiPublishingProvider (future: Facebook Graph API)
 */
interface ProviderInterface
{
    /** Machine-readable provider key: BROWSER | SIMULATED | OFFICIAL_API. */
    public function key(): string;

    /** Human label for the UI. */
    public function label(): string;

    /** True when this provider needs a live Windows worker to execute jobs. */
    public function requiresWorker(): bool;

    /**
     * Does this provider claim it can publish the given job type?
     * Used by the dispatcher to reject unsupported combinations early instead
     * of failing inside a browser session.
     */
    public function supports(string $jobType): bool;

    /**
     * Validate a job's payload before it is allowed into the queue.
     *
     * @param array<string,mixed> $job
     * @return array{ok:bool,errors:list<string>}
     */
    public function validateJob(array $job): array;

    /**
     * Describe how the worker should execute this job. The returned array is
     * attached to the job payload and consumed by the Windows worker.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function executionPlan(array $job): array;

    /** Job types this provider accepts. @return list<string> */
    public function supportedJobTypes(): array;
}
