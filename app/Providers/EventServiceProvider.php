<?php

namespace App\Providers;

use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
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
