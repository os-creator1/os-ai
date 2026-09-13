<?php

namespace App\Providers;

use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Conversation\InboundMessageReceived;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Listeners\Automation\Workflow\EnrollFromInboundMessage;
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
