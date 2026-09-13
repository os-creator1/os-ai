<?php

namespace App\Listeners\Coo;

use App\Enums\Coo\CooInsightTrigger;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Jobs\Coo\GenerateCooInsight;

/**
 * Contract §8.2 E-2 — Business Opportunity work finished (it completed, or its
 * execution succeeded or failed), so whether AI should now explain the period
 * is worth asking again.
 *
 * Owner-approved v1 approximation (contract Appendix G): the product does not
 * record which Opportunity C-2 actually rendered on Home, so every such event
 * is only a trigger CANDIDATE — not proof the move was surfaced. Nothing here
 * or on Home records surfacing history.
 *
 * It decides nothing itself. It queues one GenerateCooInsight(E-2), whose
 * condition is "E-1 still holds afterwards" and whose every gate (entitlement,
 * dormancy, budget, identical facts already cached) runs off-request. With AI
 * switched off it does not even queue. Kept apart from InvalidateCooInsights,
 * which must never queue anything.
 */
class TriggerCooInsightOnWorkFinished
{
    public function handleOpportunityCompleted(OpportunityCompleted $event): void
    {
        $this->queue($event->businessId);
    }

    public function handleOpportunityExecutionSucceeded(OpportunityExecutionSucceeded $event): void
    {
        $this->queue($event->businessId);
    }

    public function handleOpportunityExecutionFailed(OpportunityExecutionFailed $event): void
    {
        $this->queue($event->businessId);
    }

    private function queue(int $businessId): void
    {
        if (! (bool) config('services.openai.active')) {
            return;
        }

        GenerateCooInsight::dispatch($businessId, CooInsightTrigger::WorkFinished->value);
    }
}
