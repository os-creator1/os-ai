<?php

namespace App\Repositories\Eloquent;

use App\Models\OpportunityActionExecution;
use App\Repositories\Contracts\OpportunityActionExecutionRepository;
use Illuminate\Support\Arr;

class EloquentOpportunityActionExecutionRepository extends EloquentBaseRepository implements OpportunityActionExecutionRepository
{
    /**
     * Only these fields may ever be written after creation (RFC-002 §15.4).
     * opportunity_id, action_key, recommended_action_hash,
     * action_schema_version, occurrence_number, attempt_number,
     * idempotency_key, initiated_by_user_id, initiated_by_type, and
     * completion_policy are immutable once the row exists.
     */
    private const MUTABLE_FIELDS = [
        'status',
        'safe_result_summary',
        'safe_error_summary',
        'started_at',
        'completed_at',
    ];

    public function __construct(OpportunityActionExecution $execution)
    {
        parent::__construct($execution);
    }

    public function findForUpdate(int $id): ?OpportunityActionExecution
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findActiveForOpportunity(int $opportunityId): ?OpportunityActionExecution
    {
        return $this->query()
            ->where('opportunity_id', $opportunityId)
            ->whereIn('status', ['pending', 'running'])
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?OpportunityActionExecution
    {
        return $this->query()->where('idempotency_key', $key)->first();
    }

    public function nextAttemptNumberForUpdate(int $opportunityId): int
    {
        $maxAttempt = $this->query()
            ->where('opportunity_id', $opportunityId)
            ->lockForUpdate()
            ->max('attempt_number');

        return ($maxAttempt ?? 0) + 1;
    }

    public function create(array $attributes): OpportunityActionExecution
    {
        /** @var OpportunityActionExecution $execution */
        $execution = $this->make(Arr::only($attributes, array_merge(
            [
                'opportunity_id',
                'action_key',
                'recommended_action_hash',
                'action_schema_version',
                'occurrence_number',
                'attempt_number',
                'idempotency_key',
                'initiated_by_user_id',
                'initiated_by_type',
                'completion_policy',
            ],
            self::MUTABLE_FIELDS
        )));
        $execution->save();

        return $execution;
    }

    public function update(OpportunityActionExecution $execution, array $attributes): OpportunityActionExecution
    {
        $execution->fill(Arr::only($attributes, self::MUTABLE_FIELDS));
        $execution->save();

        return $execution;
    }
}
