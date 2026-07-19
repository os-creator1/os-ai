<?php

namespace App\Repositories\Contracts;

use App\Models\OpportunityTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (RFC-002 §15.5, §41) — deliberately no update() method.
 */
interface OpportunityTransitionRepository extends BaseRepository
{
    /**
     * @return Collection<int, OpportunityTransition>
     */
    public function forOpportunity(int $opportunityId): Collection;

    public function create(array $attributes): OpportunityTransition;
}
