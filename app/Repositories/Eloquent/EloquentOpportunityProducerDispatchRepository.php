<?php

namespace App\Repositories\Eloquent;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Enums\Business\BusinessStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\OpportunityProducerDispatch;
use App\Repositories\Contracts\OpportunityProducerDispatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * COO C-1 — the atomic half of automatic producer triggering.
 *
 * Both claims are ONE conditional UPDATE each, and the affected-row count is
 * the arbiter. That is deliberate and is the whole concurrency argument:
 *
 *  - the row is created first with insertOrIgnore(), which the unique
 *    (business_id, worker_key) key makes safe for any number of racing
 *    creators;
 *  - the UPDATE then matches only while the window is still unclaimed, so of N
 *    racing processes exactly one sees a changed row and the rest see zero.
 *    MySQL evaluates each such UPDATE under its own row lock, so there is no
 *    read-then-write gap for a competitor to slip into;
 *  - nothing is held in memory between calls, so multiple PHP processes and
 *    multiple queue workers coordinate through the row alone.
 *
 * A matched UPDATE always changes the value it sets (the WHERE proves the
 * stored value is older than what is being written), so MySQL's
 * "affected rows counts only changed rows" behaviour can never turn a genuine
 * win into a reported loss.
 */
class EloquentOpportunityProducerDispatchRepository extends EloquentBaseRepository implements OpportunityProducerDispatchRepository
{
    public function __construct(OpportunityProducerDispatch $dispatch)
    {
        parent::__construct($dispatch);
    }

    public function claimDebounceWindow(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $windowStart,
        CarbonImmutable $now,
    ): bool {
        $this->ensurePairRow($businessId, $workerKey, $now);

        $affected = $this->pairQuery($businessId, $workerKey)
            ->where(function ($query) use ($windowStart): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhere('last_dispatched_at', '<', $windowStart);
            })
            ->update([
                'last_dispatched_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    public function claimDailySweep(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $day,
        CarbonImmutable $now,
    ): bool {
        $this->ensurePairRow($businessId, $workerKey, $now);

        $affected = $this->pairQuery($businessId, $workerKey)
            ->where(function ($query) use ($day): void {
                $query->whereNull('last_swept_on')
                    ->orWhere('last_swept_on', '<', $day->toDateString());
            })
            ->update([
                'last_swept_on' => $day->toDateString(),
                'last_dispatched_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    public function sweepCandidates(
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $day,
        CarbonImmutable $staleBefore,
        int $afterBusinessId,
        int $limit,
    ): Collection {
        return DB::table('businesses as b')
            ->leftJoin('opportunity_producer_dispatches as d', function ($join) use ($workerKey): void {
                $join->on('d.business_id', '=', 'b.id')
                    ->where('d.worker_key', '=', $workerKey->value);
            })
            ->where('b.status', BusinessStatus::Active->value)
            ->where('b.id', '>', $afterBusinessId)
            ->where(function ($query) use ($day): void {
                $query->whereNull('d.last_swept_on')
                    ->orWhere('d.last_swept_on', '<', $day->toDateString());
            })
            // A Business that already produced a successful run inside the
            // staleness window needs no scheduled nudge today. The event path
            // covers everything that changed since.
            ->whereNotExists(function ($query) use ($workerKey, $staleBefore): void {
                $query->select(DB::raw(1))
                    ->from('opportunity_runs as r')
                    ->whereColumn('r.business_id', 'b.id')
                    ->where('r.worker_key', $workerKey->value)
                    ->where('r.status', OpportunityRunStatus::Succeeded->value)
                    ->where('r.completed_at', '>=', $staleBefore);
            })
            ->orderBy('b.id')
            ->limit($limit)
            ->pluck('b.id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function hasHealthyActiveRun(
        int $businessId,
        OpportunityWorkerKey $workerKey,
        CarbonImmutable $heartbeatCutoff,
    ): bool {
        return DB::table('opportunity_runs')
            ->where('business_id', $businessId)
            ->where('worker_key', $workerKey->value)
            ->where('status', OpportunityRunStatus::Running->value)
            ->where('heartbeat_at', '>=', $heartbeatCutoff)
            ->exists();
    }

    public function findForPair(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityProducerDispatch
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->where('worker_key', $workerKey->value)
            ->first();
    }

    /**
     * Create the pair's row if it is missing. insertOrIgnore() so that racing
     * creators produce one row and no exception; the unique key is what makes
     * that true, not a check-then-insert.
     */
    private function ensurePairRow(int $businessId, OpportunityWorkerKey $workerKey, CarbonImmutable $now): void
    {
        DB::table('opportunity_producer_dispatches')->insertOrIgnore([
            'business_id' => $businessId,
            'worker_key' => $workerKey->value,
            'last_dispatched_at' => null,
            'last_swept_on' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function pairQuery(int $businessId, OpportunityWorkerKey $workerKey): \Illuminate\Database\Query\Builder
    {
        return DB::table('opportunity_producer_dispatches')
            ->where('business_id', $businessId)
            ->where('worker_key', $workerKey->value);
    }
}
