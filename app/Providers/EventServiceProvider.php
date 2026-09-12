<?php

namespace App\Providers;

use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Listeners\Opportunity\TriggerBusinessAdvisorProducer;
use App\Listeners\Usage\InitializeBusinessUsageProfile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
