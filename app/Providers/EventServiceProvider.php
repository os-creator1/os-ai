<?php

namespace App\Providers;

use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Conversation\InboundMessageReceived;
use App\Events\Crm\CrmOpportunityCreated;
use App\Events\Crm\CrmOpportunityLost;
use App\Events\Crm\CrmOpportunityStageChanged;
use App\Events\Crm\CrmOpportunityWon;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Events\Opportunity\OpportunityCompleted;
use App\Events\Opportunity\OpportunityDismissed;
use App\Events\Opportunity\OpportunityExecutionFailed;
use App\Events\Opportunity\OpportunityExecutionSucceeded;
use App\Events\Website\WebsitePublished;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Listeners\Automation\Workflow\EnrollFromCrmOpportunityEvent;
use App\Listeners\Automation\Workflow\EnrollFromInboundMessage;
use App\Listeners\Coo\InvalidateCooInsights;
use App\Listeners\Coo\TriggerCooInsightOnWorkFinished;
use App\Listeners\Opportunity\TriggerBusinessAdvisorProducer;
use App\Listeners\Support\ResetRequestScopedCacheAtJobBoundary;
use App\Listeners\Usage\InitializeBusinessUsageProfile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        BusinessCreated::class => [
            InitializeBusinessUsageProfile::class.'@handleBusinessCreated',
        ],
        BusinessAssignedToWorkspace::class => [
            InitializeBusinessUsageProfile::class.'@handleBusinessAssignedToWorkspace',
        ],
        // COO C-1 — automatic Business Advisor producer triggering. The
        // listener decides nothing about whether work happens: every gate
        // (engine enabled, Business active, healthy run, debounce) lives in
        // OpportunityProducerTrigger, so with the engine disabled these
        // mappings are inert.
        BusinessUpdated::class => [
            TriggerBusinessAdvisorProducer::class.'@handleBusinessUpdated',
            // AI-3 §9.3 — a cached COO insight described the old profile.
            InvalidateCooInsights::class.'@handleBusinessUpdated',
        ],
        BusinessPrimaryLocationUpdated::class => [
            TriggerBusinessAdvisorProducer::class.'@handleBusinessPrimaryLocationUpdated',
        ],
        BusinessServicesSynced::class => [
            TriggerBusinessAdvisorProducer::class.'@handleBusinessServicesSynced',
        ],
        CustomerOnboardingCompleted::class => [
            TriggerBusinessAdvisorProducer::class.'@handleCustomerOnboardingCompleted',
        ],
        // Automations V2-F — an authoritatively attributed inbound message.
        // Queued: the webhook's provider is not kept waiting on enrollment.
        InboundMessageReceived::class => [
            EnrollFromInboundMessage::class,
        ],
        // Automations V2 — CRM sales opportunity facts (App\Events\Crm, never the
        // Advisor's App\Events\Opportunity). The CRM emits after commit; this
        // queued listener hands each to its trigger source.
        CrmOpportunityCreated::class => [
            EnrollFromCrmOpportunityEvent::class,
        ],
        CrmOpportunityStageChanged::class => [
            EnrollFromCrmOpportunityEvent::class,
        ],
        CrmOpportunityWon::class => [
            EnrollFromCrmOpportunityEvent::class,
        ],
        CrmOpportunityLost::class => [
            EnrollFromCrmOpportunityEvent::class,
        ],
        // Unified Business Home §9.3 (AI-3) — cached COO insights stop being
        // shown when their facts stop holding. Invalidation queues nothing;
        // only the separate E-2 trigger below may queue a regeneration, and
        // it runs after invalidation so the job never reads a retired row.
        OpportunityCompleted::class => [
            InvalidateCooInsights::class.'@handleOpportunityCompleted',
            TriggerCooInsightOnWorkFinished::class.'@handleOpportunityCompleted',
        ],
        OpportunityDismissed::class => [
            InvalidateCooInsights::class.'@handleOpportunityDismissed',
        ],
        OpportunityExecutionSucceeded::class => [
            InvalidateCooInsights::class.'@handleOpportunityExecutionSucceeded',
            TriggerCooInsightOnWorkFinished::class.'@handleOpportunityExecutionSucceeded',
        ],
        OpportunityExecutionFailed::class => [
            InvalidateCooInsights::class.'@handleOpportunityExecutionFailed',
            TriggerCooInsightOnWorkFinished::class.'@handleOpportunityExecutionFailed',
        ],
        WebsitePublished::class => [
            InvalidateCooInsights::class.'@handleWebsitePublished',
        ],
        GoogleBusinessProfileConnected::class => [
            InvalidateCooInsights::class.'@handleGoogleBusinessProfileConnected',
        ],
        GoogleBusinessProfileDisconnected::class => [
            InvalidateCooInsights::class.'@handleGoogleBusinessProfileDisconnected',
        ],
        GoogleBusinessProfileConnectionRevoked::class => [
            InvalidateCooInsights::class.'@handleGoogleBusinessProfileConnectionRevoked',
        ],
        // RequestScopedCache's queue-job boundary: a worker's console Request
        // outlives every job it runs, so the memo is flushed at both edges of
        // each worker job (sync jobs, which run inside their dispatcher, are
        // left alone). One listener for every job class.
        JobProcessing::class => [
            ResetRequestScopedCacheAtJobBoundary::class,
        ],
        JobProcessed::class => [
            ResetRequestScopedCacheAtJobBoundary::class,
        ],
        JobExceptionOccurred::class => [
            ResetRequestScopedCacheAtJobBoundary::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //
    }
}
