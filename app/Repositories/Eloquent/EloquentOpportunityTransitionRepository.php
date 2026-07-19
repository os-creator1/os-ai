<?php

namespace App\Repositories\Eloquent;

use App\Models\OpportunityTransition;
use App\Repositories\Contracts\OpportunityTransitionRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-002 §15.5, §41) — deliberately no update() method.
 */
class EloquentOpportunityTransitionRepository extends EloquentBaseRepository implements OpportunityTransitionRepository
{
    public function __construct(OpportunityTransition $transition)
    {
        parent::__construct($transition);
    }

    public function forOpportunity(int $opportunityId): Collection
    {
        return $this->query()
            ->where('opportunity_id', $opportunityId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function create(array $attributes): OpportunityTransition
    {
        /** @var OpportunityTransition $transition */
        $transition = $this->make(Arr::only($attributes, [
            'opportunity_id',
            'category',
            'from_status',
            'to_status',
            'actor_type',
            'actor_user_id',
            'opportunity_run_id',
            'action_execution_id',
            'reason_code',
            'safe_note',
        ]));
        $transition->save();

        return $transition;
    }
}
