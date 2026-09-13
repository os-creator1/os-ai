<?php

namespace App\Listeners\Coo;

use App\Events\Business\BusinessUpdated;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Website\WebsitePublished;
use App\Library\Coo\Insight\CooInsightInvalidator;
use App\Repositories\Contracts\OpportunityRepository;

/**
 * Contract §9.3 — cached COO insights stop being shown when their facts stop
 * holding.
 *
 * Synchronous on purpose, and cheap: every handler is one bounded UPDATE (a
 * work event also reads the one Opportunity it names). It queues NOTHING and
 * spends NOTHING — a new insight is bought only by a §8.2 trigger, never
 * because an old one was retired. Every event here already dispatches after
 * its transaction commits.
 */
class InvalidateCooInsights
{
    public function __construct(
        private readonly CooInsightInvalidator $invalidator,
        private readonly OpportunityRepository $opportunities,
    ) {
    }

    public function handleOpportunityCompleted(OpportunityCompleted $event): void
    {
        $this->opportunityWork($event->businessId, $event->opportunityId);
    }

    public function handleOpportunityDismissed(OpportunityDismissed $event): void
    {
        $this->opportunityWork($event->businessId, $event->opportunityId);
    }

    public function handleOpportunityExecutionSucceeded(OpportunityExecutionSucceeded $event): void
    {
        $this->opportunityWork($event->businessId, $event->opportunityId);
    }

    public function handleOpportunityExecutionFailed(OpportunityExecutionFailed $event): void
    {
        $this->opportunityWork($event->businessId, $event->opportunityId);
    }

    public function handleWebsitePublished(WebsitePublished $event): void
    {
        $this->invalidator->invalidateContext($event->businessId);
    }

    public function handleGoogleBusinessProfileConnected(GoogleBusinessProfileConnected $event): void
    {
        $this->invalidator->invalidateContext($event->businessId);
    }

    public function handleGoogleBusinessProfileDisconnected(GoogleBusinessProfileDisconnected $event): void
    {
        $this->invalidator->invalidateContext($event->businessId);
    }

    public function handleGoogleBusinessProfileConnectionRevoked(GoogleBusinessProfileConnectionRevoked $event): void
    {
        $this->invalidator->invalidateContext($event->businessId);
    }

    public function handleBusinessUpdated(BusinessUpdated $event): void
    {
        $this->invalidator->invalidateContext($event->businessId);
    }

    private function opportunityWork(int $businessId, int $opportunityId): void
    {
        // Scoped by the event's own Business: an id from another tenant finds nothing.
        $type = $this->opportunities->findOwned($opportunityId, $businessId)?->type;

        $this->invalidator->invalidateForOpportunityWork($businessId, $opportunityId, $type !== null ? (string) $type : null);
    }
}
