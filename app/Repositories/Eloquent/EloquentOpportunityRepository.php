<?php

namespace App\Repositories\Eloquent;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
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

    public function findOwned(int $id, int $businessId): ?Opportunity
    {
        return $this->query()
            ->where('id', $id)
            ->where('business_id', $businessId)
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
            ->orderBy('snoozed_until')
            ->orderBy('id')
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
            ->orderByRaw('CASE WHEN freshness = ? THEN 0 ELSE 1 END', ['current'])
            ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', ['open', 'awaiting_approval', 'in_progress'])
            ->orderByDesc('priority_score')
            ->orderByDesc('impact')
            ->orderByDesc('urgency')
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->paginate(self::MAX_PER_PAGE);
    }

    public function topForCustomer(Business $business, int $limit): Collection
    {
        $actionableStatuses = [
            OpportunityStatus::Open->value,
            OpportunityStatus::AwaitingApproval->value,
            OpportunityStatus::InProgress->value,
        ];

        return $this->query()
            ->where('business_id', $business->id)
            ->where('freshness', OpportunityFreshness::Current->value)
            ->whereIn('status', $actionableStatuses)
            ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', $actionableStatuses)
            ->orderByDesc('priority_score')
            ->orderByDesc('impact')
            ->orderByDesc('urgency')
            ->orderBy('first_detected_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
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
