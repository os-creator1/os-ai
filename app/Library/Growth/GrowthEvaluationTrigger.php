<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Jobs\Growth\RunGrowthEvaluation;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;

/**
 * When a Growth evaluation runs (Growth Center §52-53):
 *
 *   - a debounced, event-driven refresh for the few high-value domain events
 *     that change the answer immediately (a payment fails, a document is
 *     fully paid, the Website is published, a deal is won or lost); and
 *   - a once-a-day sweep that evaluates every active Business, so nothing
 *     depends on an event having been emitted.
 *
 * Both reuse the Opportunity Engine's own claim table
 * (opportunity_producer_dispatches). One full Growth evaluation spans the
 * sales / website / seo / reputation workers, so it claims under a single
 * identity — the `sales` worker key — and there is no second claim table and
 * no event storm: a burst of events costs one evaluation per debounce window.
 *
 * Every gate (engine enabled, Business active, no healthy run already going)
 * lives here, so with the engine disabled the listeners are inert.
 */
final class GrowthEvaluationTrigger
{
    public const CLAIM_WORKER = OpportunityWorkerKey::Sales;

    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly OpportunityProducerDispatchRepository $dispatches,
    ) {
    }

    public function triggerFromEvent(int $businessId, ?CarbonImmutable $now = null): bool
    {
        if (! $this->eligible($businessId)) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        if ($this->hasHealthyActiveRun($businessId, $now)) {
            return false;
        }

        $claimed = $this->dispatches->claimDebounceWindow(
            $businessId,
            self::CLAIM_WORKER,
            $now->subMinutes($this->debounceMinutes()),
            $now,
        );

        if (! $claimed) {
            return false;
        }

        RunGrowthEvaluation::dispatch($businessId);

        return true;
    }

    public function triggerDailySweep(int $businessId, ?CarbonImmutable $now = null): bool
    {
        if (! $this->eligible($businessId)) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        if (! $this->dispatches->claimDailySweep($businessId, self::CLAIM_WORKER, $now, $now)) {
            return false;
        }

        if ($this->hasHealthyActiveRun($businessId, $now)) {
            return false;
        }

        RunGrowthEvaluation::dispatch($businessId);

        return true;
    }

    /** The owner's own "Refresh" — rate-limited by the route, not debounced here. */
    public function triggerManual(int $businessId, ?CarbonImmutable $now = null): bool
    {
        if (! $this->eligible($businessId)) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        if ($this->hasHealthyActiveRun($businessId, $now)) {
            return false;
        }

        RunGrowthEvaluation::dispatch($businessId);

        return true;
    }

    private function eligible(int $businessId): bool
    {
        if (! config('opportunity.enabled', false)) {
            return false;
        }

        $business = $this->businesses->findById($businessId);

        return $business !== null && $business->status === BusinessStatus::Active;
    }

    private function hasHealthyActiveRun(int $businessId, CarbonImmutable $now): bool
    {
        return $this->dispatches->hasHealthyActiveRun(
            $businessId,
            self::CLAIM_WORKER,
            $now->subMinutes((int) config('opportunity.run_timeout_minutes', 30)),
        );
    }

    private function debounceMinutes(): int
    {
        $minutes = (int) config('growth.evaluation.debounce_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }
}
