<?php

namespace App\Library\Opportunity;

use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;

/**
 * COO C-1 — the ONE gate both automatic producer paths pass through: the
 * event listener and the daily sweep.
 *
 * It decides only WHETHER to enqueue the existing
 * RunBusinessAdvisorOpportunityProducer for one Business. It produces no
 * candidate, writes no run, and knows nothing about what the producer will
 * find — OpportunityManager keeps every bit of that (RFC-002 §40), and the job
 * remains the only thing that calls beginRun().
 *
 * Four refusals, in this order, and each one enqueues nothing:
 *
 *  1. the engine is disabled — and then NOTHING is written at all, not even a
 *     coordination row, so a disabled install cannot be told from a
 *     never-triggered one (contract §7.3 fail-safe, T-C1-4);
 *  2. the Business is gone or not active;
 *  3. a healthy run already owns this work, so a second job would only be
 *     collapsed by beginRun() after the queue had already paid for it;
 *  4. the window is already claimed by another request, process or worker.
 *
 * Tenancy: the only thing that ever crosses into the queue is one Business id
 * that was read back from persistence here, and the job re-reads that Business
 * itself. There is no actor identity in any of it — no Auth::id(), no session,
 * no request state — so a background run belongs to exactly one Business and
 * can produce for no other.
 */
final class OpportunityProducerTrigger
{
    /**
     * The one worker this slice triggers. The Business Advisor job is
     * deliberately not a generic dispatcher (RFC-002 worker guide), so a
     * future worker brings its own job and its own trigger call rather than
     * widening this one.
     */
    public const WORKER_KEY = OpportunityWorkerKey::BusinessAdvisor;

    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly OpportunityProducerDispatchRepository $dispatches,
    ) {
    }

    /**
     * The event-driven path: a Business fact that the Advisor reads actually
     * changed. Debounced, so a burst of edits costs one run.
     *
     * @return bool whether a producer job was enqueued
     */
    public function triggerFromChange(int $businessId, ?CarbonImmutable $now = null): bool
    {
        if (! $this->engineEnabled()) {
            return false;
        }

        $now = $now ?? CarbonImmutable::now();

        if (! $this->businessIsEligible($businessId)) {
            return false;
        }

        if ($this->hasHealthyActiveRun($businessId, $now)) {
            // Deliberately does NOT consume the debounce window: when the
            // active run finishes, the next real change still gets a prompt
            // run instead of waiting out a window it never used.
            return false;
        }

        $claimed = $this->dispatches->claimDebounceWindow(
            $businessId,
            self::WORKER_KEY,
            $now->subMinutes($this->debounceMinutes()),
            $now,
        );

        if (! $claimed) {
            return false;
        }

        RunBusinessAdvisorOpportunityProducer::dispatch($businessId);

        return true;
    }

    /**
     * The daily safety-net path, for a Business the event path has not covered
     * recently. At most one of these per Business per day, whatever happens.
     *
     * @return bool whether a producer job was enqueued
     */
    public function triggerDailySweep(int $businessId, ?CarbonImmutable $now = null): bool
    {
        if (! $this->engineEnabled()) {
            return false;
        }

        $now = $now ?? CarbonImmutable::now();

        if (! $this->businessIsEligible($businessId)) {
            return false;
        }

        // The day is claimed before the active-run check on purpose: if a run
        // is already under way for this Business, the day's nudge has served
        // its purpose and must not be retried by a later sweep of the same
        // day.
        $claimed = $this->dispatches->claimDailySweep(
            $businessId,
            self::WORKER_KEY,
            $now,
            $now,
        );

        if (! $claimed) {
            return false;
        }

        if ($this->hasHealthyActiveRun($businessId, $now)) {
            return false;
        }

        RunBusinessAdvisorOpportunityProducer::dispatch($businessId);

        return true;
    }

    private function engineEnabled(): bool
    {
        return (bool) config('opportunity.enabled', false);
    }

    /**
     * Read back from persistence, never trusted from the event: an event
     * carries an id, and by the time a listener runs the Business may have
     * been archived or deleted.
     */
    private function businessIsEligible(int $businessId): bool
    {
        $business = $this->businesses->findById($businessId);

        return $business !== null && $business->status === BusinessStatus::Active;
    }

    private function hasHealthyActiveRun(int $businessId, CarbonImmutable $now): bool
    {
        return $this->dispatches->hasHealthyActiveRun(
            $businessId,
            self::WORKER_KEY,
            $now->subMinutes((int) config('opportunity.run_timeout_minutes', 30)),
        );
    }

    private function debounceMinutes(): int
    {
        $minutes = (int) config('opportunity.trigger_debounce_minutes', 15);

        // A zero or negative window would make every edit in a burst its own
        // run, which is the failure this class exists to prevent.
        return $minutes > 0 ? $minutes : 15;
    }
}
