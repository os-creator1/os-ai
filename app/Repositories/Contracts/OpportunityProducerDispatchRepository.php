<?php

namespace App\Repositories\Contracts;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\OpportunityProducerDispatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * COO C-1 — the durable claims that keep automatic producer triggering from
 * enqueueing the same work twice.
 *
 * Every claim is atomic and returns whether THIS caller won it. A false is not
 * an error: it means another request, process or queue worker already holds
 * the window, and this caller must enqueue nothing.
 */
interface OpportunityProducerDispatchRepository
{
    /**
     * Claim the event-driven debounce window for one (Business, worker).
     *
     * Wins only when the pair has not been dispatched since $windowStart, so a
     * burst of edits inside one window produces exactly one winner however
     * many processes race.
     */
    public function claimDebounceWindow(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $windowStart,
        CarbonImmutable $now,
    ): bool;

    /**
     * Claim the daily sweep slot for one (Business, worker) on $day.
     *
     * Wins at most once per day, which is what makes a second sweep of the
     * same day dispatch nothing rather than duplicating the day's work.
     */
    public function claimDailySweep(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $day,
        CarbonImmutable $now,
    ): bool;

    /**
     * One bounded, deterministic page of Businesses the daily sweep should
     * consider: active Businesses, ordered by id, whose id is greater than
     * $afterBusinessId, that have not been swept for $day and have no
     * successful run for this worker since $staleBefore.
     *
     * Keyset paging by id — never an offset scan, never the whole table.
     *
     * @return Collection<int, int> Business ids, ascending
     */
    public function sweepCandidates(
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $day,
        CarbonImmutable $staleBefore,
        int $afterBusinessId,
        int $limit,
    ): Collection;

    /**
     * Does this (Business, worker) pair have a run that is still running AND
     * whose heartbeat is not older than $heartbeatCutoff?
     *
     * Read-only, and the same healthiness test beginRun() applies under its
     * lock (RFC-002 §24.2 — a heartbeat exactly at the cutoff is still
     * healthy). It lives on THIS contract rather than on
     * OpportunityRunRepository deliberately: that interface is RFC-002's, and
     * widening it would break every in-test double that implements it. This
     * repository already reads opportunity_runs for the sweep's staleness
     * filter, so the read is at home here — and beginRun() remains the
     * authority either way. This only avoids queueing a job that would be
     * thrown away.
     */
    public function hasHealthyActiveRun(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $heartbeatCutoff,
    ): bool;

    public function findForPair(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityProducerDispatch;
}
