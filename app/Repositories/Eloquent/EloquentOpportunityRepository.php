<?php

namespace App\Repositories\Eloquent;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\Business;
use App\Models\Opportunity;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class EloquentOpportunityRepository extends EloquentBaseRepository implements OpportunityRepository
{
    private const MAX_PER_PAGE = 100;

    /**
     * Everything except the identity fields, which are immutable after
     * creation (RFC-002 §15.2): business_id, worker_key, type,
     * fingerprint_version, fingerprint, context_key, first_detected_at.
     */
    private const MUTABLE_FIELDS = [
        'title',
        'summary',
        'status',
        'freshness',
        'impact',
        'urgency',
        'effort',
        'confidence',
        'goal_relevance_rank',
        'evidence_freshness_rank',
        'priority_score',
        'scoring_version',
        'scored_at',
        'evidence',
        'recommended_action',
        'recommended_action_hash',
        'action_schema_version',
        'occurrence_number',
        'last_confirmed_run_id',
        'last_confirmed_at',
        'snoozed_until',
        'completed_at',
        'dismissed_at',
        'stale_at',
    ];

    public function __construct(Opportunity $opportunity)
    {
        parent::__construct($opportunity);
    }

    public function findByFingerprintForUpdate(string $fingerprint): ?Opportunity
    {
        return $this->query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
    }

    public function findOwnedForUpdate(int $id, int $businessId): ?Opportunity
    {
        return $this->query()
            ->where('id', $id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();
    }

    public function currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId): Collection
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->where('worker_key', $workerKey)
            ->where('freshness', 'current')
            ->where(function ($query) use ($excludeRunId) {
                $query->whereNull('last_confirmed_run_id')
                    ->orWhere('last_confirmed_run_id', '!=', $excludeRunId);
            })
            ->lockForUpdate()
            ->get();
    }

    public function expiredSnoozesBatch(int $limit): Collection
    {
        return $this->query()
            ->where('status', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->limit($limit)
            ->get();
    }

    public function paginateForCustomer(Business $business, array $filters): LengthAwarePaginator
    {
        $filters = Arr::only($filters, ['status', 'freshness', 'worker_key']);

        $query = $this->query()->where('business_id', $business->id);

        foreach ($filters as $column => $value) {
            if (filled($value)) {
                $query->where($column, $value);
            }
        }

        return $query
            ->orderByDesc('priority_score')
            ->orderByDesc('impact')
            ->orderByDesc('urgency')
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->paginate(self::MAX_PER_PAGE);
    }

    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        $filters = Arr::only($filters, ['status', 'freshness', 'worker_key', 'business_id']);

        $query = $this->query();

        foreach ($filters as $column => $value) {
            if (filled($value)) {
                $query->where($column, $value);
            }
        }

        return $query->orderByDesc('id')->paginate(self::MAX_PER_PAGE);
    }

    public function create(array $attributes): Opportunity
    {
        /** @var Opportunity $opportunity */
        $opportunity = $this->make(Arr::only($attributes, array_merge(
            ['business_id', 'worker_key', 'type', 'fingerprint_version', 'fingerprint', 'context_key', 'first_detected_at'],
            self::MUTABLE_FIELDS
        )));
        $opportunity->save();

        return $opportunity;
    }

    public function update(Opportunity $opportunity, array $attributes): Opportunity
    {
        $opportunity->fill(Arr::only($attributes, self::MUTABLE_FIELDS));
        $opportunity->save();

        return $opportunity;
    }
}
