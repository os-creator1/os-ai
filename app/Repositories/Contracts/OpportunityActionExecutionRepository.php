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

    /**
     * The most recent execution for this Opportunity regardless of status
     * (pending/running/succeeded/failed) — for customer-facing read display
     * only. Ordered by attempt_number descending, then id descending as a
     * deterministic tie-breaker. Unlocked: this is a display read, never
     * mutated by the caller.
     */
    public function findLatestForOpportunity(int $opportunityId): ?OpportunityActionExecution;

    public function findByIdempotencyKey(string $key): ?OpportunityActionExecution;

    public function nextAttemptNumberForUpdate(int $opportunityId): int;

    /**
     * The most recent `failed` execution for this Opportunity whose bound
     * action identity still matches the live Opportunity exactly (RFC-002
     * §31) — an unrelated historical failure (another occurrence, action
     * hash, schema version, or action_key) is never returned. Unlocked:
     * this is terminal history, never mutated by the caller.
     */
    public function findLatestFailedMatching(
        int $opportunityId,
        int $occurrenceNumber,
        string $recommendedActionHash,
        int $actionSchemaVersion,
        string $actionKey
    ): ?OpportunityActionExecution;

    public function create(array $attributes): OpportunityActionExecution;

    public function update(OpportunityActionExecution $execution, array $attributes): OpportunityActionExecution;
}
