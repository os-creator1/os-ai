<?php

namespace App\Jobs\Website;

use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one guided website generation/rebuild off the HTTP request. The request has already taken the Website's
 * generation lease and hands its fencing token here; this job restarts the lease clock, runs the unchanged commit
 * service (idempotency, validation, atomic draft replacement, final fence check) and always releases the lease.
 *
 * Timing: the provider call is bounded at 60s with one retry, so a run needs well under TIMEOUT_SECONDS; the queue
 * kills a run at TIMEOUT_SECONDS, which is shorter than the 300s lease (so a lease never expires under a live job)
 * and far shorter than the database queue's retry_after (so a killed job is never handed to a second worker while
 * the first is alive). tries = 1: a crashed run is never repeated automatically, because a repeat would spend a second
 * AI request; the owner presses Regenerate again. A redelivered copy finds the lease gone and does nothing.
 */
class RunGuidedWebsiteGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const TIMEOUT_SECONDS = 240;

    public int $tries = 1;
    public int $maxExceptions = 1;
    public int $timeout = self::TIMEOUT_SECONDS;
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $websiteId,
        public readonly int $businessId,
        public readonly string $templateKey,
        public readonly int $actorUserId,
        public readonly string $idempotencyKey,
        public readonly string $mode,
        public readonly string $leaseToken,
        public readonly ?array $customSection,
        public readonly ?array $customerFaq,
        public readonly ?array $catalogItemUids,
        public readonly ?array $serviceAreas,
        public readonly ?array $blueprint,
    ) {
        $this->onQueue('default');
    }

    public function handle(GuidedGenerationCommitService $service, WebsiteGenerationCoordinator $coordinator): void
    {
        $website = Website::find($this->websiteId);
        $business = Business::find($this->businessId);

        if ($website === null || $business === null || ! $coordinator->heartbeat($website, $this->leaseToken)) {
            // The lease was reclaimed (or the records are gone): this run is obsolete and must not touch anything.
            return;
        }

        try {
            $template = WebsiteTemplate::where('key', $this->templateKey)->where('is_active', true)->firstOrFail();

            $args = [$business, $website, $template, $this->actorUserId, $this->idempotencyKey, $this->leaseToken, $this->customSection, $this->customerFaq, $this->catalogItemUids, $this->serviceAreas, $this->blueprint];

            $this->mode === WebsiteGuidedGenerationAttempt::MODE_REBUILD ? $service->rebuild(...$args) : $service->generateFull(...$args);
        } catch (Throwable $e) {
            $coordinator->abandon($website, $this->leaseToken, 'Generation stopped unexpectedly: ' . $e->getMessage());

            throw $e;
        } finally {
            $coordinator->release($website, $this->leaseToken);
        }
    }

    /** Queue timeout, or an exception outside handle()'s own try: never leave the lease or attempt behind. */
    public function failed(Throwable $e): void
    {
        $website = Website::find($this->websiteId);

        if ($website !== null) {
            app(WebsiteGenerationCoordinator::class)->abandon($website, $this->leaseToken, 'Generation did not finish: ' . $e->getMessage());
        }
    }
}
