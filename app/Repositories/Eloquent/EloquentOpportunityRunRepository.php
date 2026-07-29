<?php

namespace App\Repositories\Eloquent;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\OpportunityRun;
use App\Repositories\Contracts\OpportunityRunRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class EloquentOpportunityRunRepository extends EloquentBaseRepository implements OpportunityRunRepository
{
    /**
     * Only these fields may ever be written after creation (RFC-002 §15.1).
     * uid, business_id, worker_key, producer_version, and started_at are
     * immutable once the row exists.
     */
    private const MUTABLE_FIELDS = [
        'status',
        'heartbeat_at',
        'completed_at',
        'abandoned_at',
        'reason_code',
        'safe_error_summary',
    ];

    public function __construct(OpportunityRun $run)
    {
        parent::__construct($run);
    }

    public function findForUpdate(int $id): ?OpportunityRun
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityRun
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->where('worker_key', $workerKey)
            ->where('status', 'running')
            ->lockForUpdate()
            ->first();
    }

    public function paginateForBusiness(int $businessId, int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForAdmin(int $runId): ?OpportunityRun
    {
        return $this->query()
            ->with(['business.customer.user'])
            ->find($runId);
    }

    public function create(array $attributes): OpportunityRun
    {
        /** @var OpportunityRun $run */
        $run = $this->make(Arr::only($attributes, [
            'business_id', 'worker_key', 'producer_version', 'status',
            'started_at', 'heartbeat_at', 'completed_at', 'abandoned_at',
            'reason_code', 'safe_error_summary',
        ]));
        $run->save();

        return $run;
    }

    public function update(OpportunityRun $run, array $attributes): OpportunityRun
    {
        $run->fill(Arr::only($attributes, self::MUTABLE_FIELDS));
        $run->save();

        return $run;
    }
}
