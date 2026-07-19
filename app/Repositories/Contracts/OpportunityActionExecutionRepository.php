<?php

namespace App\Repositories\Contracts;

use App\Models\OpportunityActionExecution;

interface OpportunityActionExecutionRepository extends BaseRepository
{
    public function findForUpdate(int $id): ?OpportunityActionExecution;

    /**
     * The pending/running execution for this Opportunity, if any.
     */
    public function findActiveForOpportunity(int $opportunityId): ?OpportunityActionExecution;

    public function findByIdempotencyKey(string $key): ?OpportunityActionExecution;

    public function nextAttemptNumberForUpdate(int $opportunityId): int;

    public function create(array $attributes): OpportunityActionExecution;

    public function update(OpportunityActionExecution $execution, array $attributes): OpportunityActionExecution;
}
