<?php

namespace App\Providers;

use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Conversation\InboundMessageReceived;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagRemoved;
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
use App\Events\Forms\FormSubmissionRecorded;
use App\Listeners\Automation\Workflow\EnrollFromAppointmentEvent;
use App\Listeners\Automation\Workflow\EnrollFromContactTagEvent;
use App\Listeners\Automation\Workflow\EnrollFromCrmOpportunityEvent;
use App\Listeners\Automation\Workflow\EnrollFromFormSubmission;
use App\Listeners\Automation\Workflow\EnrollFromInboundMessage;
use App\Listeners\Outreach\HandleOutreachInboundMessage;
use App\Listeners\Coo\InvalidateCooInsights;
use App\Listeners\Seo\QueueSeoAuditOnWebsitePublished;
use App\Listeners\NicheBlueprint\InstallBlueprintOnBusinessCreated;
use App\Listeners\NicheBlueprint\InstallBlueprintOnFirstPlanAssigned;
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
     * Platform Automations: maps canonical domain events to Platform triggers.
     * The subscriber swallows its own failures, so it can never break the flow it listens to.
     *
     * @var array
     */
    protected $subscribe = [
        \App\Listeners\PlatformAutomation\PlatformTriggerSubscriber::class,
    ];

    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // Implementation Contract 17 §12.E — the payment receipt. The job it
        // dispatches claims `receipt_sent_at` with a conditional UPDATE, so a
        // replayed or duplicated event still sends exactly one receipt (§8.4).
        \App\Events\DocumentPaymentSucceeded::class => [
            \App\Listeners\BusinessPayments\SendReceiptOnPaymentSucceeded::class,
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handlePaymentSucceeded',
            // Automations V2 — "A payment succeeds".
            \App\Listeners\Automation\Workflow\EnrollFromDocumentEvent::class,
        ],
        // Automations V2 — "A payment fails". The finalizer emits this once per
        // payment row that transitions into `failed`; nothing else consumes it.
        \App\Events\DocumentPaymentFailed::class => [
            \App\Listeners\Automation\Workflow\EnrollFromDocumentEvent::class,
            // Growth Center — a failed payment is the one document event that
            // CREATES a finding at once; the debounced trigger keeps a burst of
            // events to one evaluation per window.
            \App\Listeners\Growth\TriggerGrowthEvaluation::class.'@handlePaymentFailed',
        ],
        // Implementation Contract 17 §12.G — Blueprint §24's "payment events
        // reach the Activity Center", extended to the whole document
        // lifecycle so the Business owner is told the same way for every one
        // of these durable transitions.
        \App\Events\DocumentSent::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleSent',
            // Automations V2 — "A proposal or document is sent".
            \App\Listeners\Automation\Workflow\EnrollFromDocumentEvent::class,
        ],
        \App\Events\DocumentSigned::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleSigned',
            // Automations V2 — "A proposal or document is signed".
            \App\Listeners\Automation\Workflow\EnrollFromDocumentEvent::class,
        ],
        \App\Events\DocumentFullyPaid::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleFullyPaid',
            \App\Listeners\Growth\TriggerGrowthEvaluation::class.'@handleFullyPaid',
        ],
        \App\Events\DocumentExpired::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleExpired',
        ],
        \App\Events\DocumentVoided::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleVoided',
        ],
        \App\Events\DocumentRefunded::class => [
            \App\Listeners\Documents\SurfaceDocumentActivityInActivityCenter::class.'@handleRefunded',
        ],
        BusinessCreated::class => [
            InitializeBusinessUsageProfile::class.'@handleBusinessCreated',
            // Contract 20 §9.1 — trigger one of exactly two into the niche
            // Blueprint installation engine. Its partner below is
            // WorkspacePlanAssigned; WorkspacePlanChanged is deliberately NOT
            // wired, because installing on an upgrade is the one thing
            // Addendum §16 forbids outright.
            InstallBlueprintOnBusinessCreated::class,
        ],
        // Contract 20 §9.1 — trigger two of two, covering the ordering where a
        // Business exists before its Workspace has any plan assignment (Agency
        // client provisioning). This is the FIRST-plan event only; it is never
        // dispatched by an upgrade or downgrade.
        WorkspacePlanAssigned::class => [
            InstallBlueprintOnFirstPlanAssigned::class,
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
            // Agency Outreach V1 (contract §9) — prospect replies. Queued; matches the Agency's own
            // Business + the sender's number and ignores everything else.
            HandleOutreachInboundMessage::class,
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
            \App\Listeners\Growth\TriggerGrowthEvaluation::class.'@handleCrmDealClosed',
        ],
        CrmOpportunityLost::class => [
            EnrollFromCrmOpportunityEvent::class,
            \App\Listeners\Growth\TriggerGrowthEvaluation::class.'@handleCrmDealClosed',
        ],
        // Automations V2 — the merged foundations' after-commit facts. Each domain
        // emits and knows nothing of workflows; these queued listeners hand each
        // fact to its trigger source (Contact Tags, Forms, Calendar).
        ContactTagAdded::class => [
            EnrollFromContactTagEvent::class,
        ],
        ContactTagRemoved::class => [
            EnrollFromContactTagEvent::class,
        ],
        FormSubmissionRecorded::class => [
            EnrollFromFormSubmission::class,
        ],
        AppointmentScheduled::class => [
            EnrollFromAppointmentEvent::class,
        ],
        AppointmentCancelled::class => [
            EnrollFromAppointmentEvent::class,
        ],
        AppointmentRescheduled::class => [
            EnrollFromAppointmentEvent::class,
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
            // Contract 18 §8.7 (Sub-slice G) — additive: queues a
            // technical SEO audit of the revision just published. It
            // swallows its own failures so a publish can never fail
            // because SEO analysis could not be queued.
            QueueSeoAuditOnWebsitePublished::class,
            // Growth Center — publishing resolves "website not published".
            \App\Listeners\Growth\TriggerGrowthEvaluation::class.'@handleWebsitePublished',
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
