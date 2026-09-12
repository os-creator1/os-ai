<?php

namespace App\Listeners\Opportunity;

use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Library\Opportunity\OpportunityProducerTrigger;
use App\Repositories\Contracts\CustomerOnboardingRepository;

/**
 * COO C-1 — the event-driven half of automatic producer triggering.
 *
 * WHY THESE FOUR EVENTS. The Business Advisor producer reads exactly one fact
 * vector, InitialBusinessSnapshotBuilder::opportunityFacts(): the Business's
 * own phone, email, website URL, description, Google Business Profile URL,
 * Facebook URL, Instagram URL and industry; its primary location and whether
 * that location satisfies its service mode; and its active services and
 * primary service. These four after-commit domain events are the complete set
 * of canonical announcements that any of that changed:
 *
 *   BusinessUpdated                 the Business record itself
 *   BusinessPrimaryLocationUpdated  the primary location
 *   BusinessServicesSynced          the active/primary services
 *   CustomerOnboardingCompleted     the moment a Business first has all of it
 *
 * They are the right trigger rather than an Eloquent `saved` hook because they
 * already exist, are dispatched by BusinessManager/OnboardingManager at the
 * one place each change is authorized, implement ShouldDispatchAfterCommit (so
 * no listener can ever see a fact that got rolled back), and name the changed
 * fields — which lets this listener ignore a change the Advisor cannot read.
 * A model hook would fire for every column, inside the transaction, from
 * anywhere.
 *
 * MATERIALITY. A BusinessUpdated that touched only, say, the timezone or the
 * canonical domain changes no Advisor fact, so it triggers nothing: the
 * changed-field list is intersected with the fields above. Note this is not
 * C-3 signal materiality (that slice is about metric movement); it is the
 * narrow question of whether this producer's own inputs moved.
 *
 * Every gate that decides whether work is actually enqueued —
 * engine-enabled, Business still active, healthy active run, debounce — lives
 * in OpportunityProducerTrigger, so both automatic paths obey one rule set.
 */
class TriggerBusinessAdvisorProducer
{
    /**
     * The Business columns the Advisor's fact vector reads. Anything outside
     * this set cannot change a candidate, so it must not cost a run.
     */
    public const MATERIAL_BUSINESS_FIELDS = [
        'phone',
        'email',
        'website_url',
        'description',
        'google_business_profile_url',
        'facebook_url',
        'instagram_url',
        // Industry decides whether a missing Instagram URL is even a fact
        // (collectOpportunityFacts()'s instagram industries).
        'industry',
    ];

    public function __construct(
        private readonly OpportunityProducerTrigger $trigger,
        private readonly CustomerOnboardingRepository $onboardings,
    ) {
    }

    public function handleBusinessUpdated(BusinessUpdated $event): void
    {
        if (array_intersect($event->changedFields, self::MATERIAL_BUSINESS_FIELDS) === []) {
            return;
        }

        $this->trigger->triggerFromChange($event->businessId);
    }

    public function handleBusinessPrimaryLocationUpdated(BusinessPrimaryLocationUpdated $event): void
    {
        $this->trigger->triggerFromChange($event->businessId);
    }

    public function handleBusinessServicesSynced(BusinessServicesSynced $event): void
    {
        $this->trigger->triggerFromChange($event->businessId);
    }

    /**
     * Onboarding announces itself by onboarding id, not Business id, so the
     * Business is resolved from the persisted onboarding record. A record that
     * carries no Business yet triggers nothing — there is nothing to produce
     * for.
     */
    public function handleCustomerOnboardingCompleted(CustomerOnboardingCompleted $event): void
    {
        $onboarding = $this->onboardings->findById($event->onboardingId);
        $businessId = $onboarding?->business_id;

        if ($businessId === null) {
            return;
        }

        $this->trigger->triggerFromChange((int) $businessId);
    }
}
