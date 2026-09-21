<?php

namespace Tests\Feature\Calendar;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CalendarActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_is_available_and_packaged_for_each_customer_plan(): void
    {
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Calendar->value));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(PlatformFeature::Calendar->value));
        foreach (['core', 'growth', 'agency'] as $tier) {
            $catalogId = DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id');
            $this->assertNotNull($catalogId);
            $this->assertTrue(DB::table('workspace_plan_features')
                ->where('workspace_plan_catalog_id', $catalogId)
                ->where('feature_key', PlatformFeature::Calendar->value)->exists());
        }
    }

    public function test_public_and_authenticated_calendar_routes_are_registered(): void
    {
        foreach (['public.booking.show', 'public.booking.store', 'public.booking.confirmed',
            'customer.workspaces.businesses.calendar.schedule',
            'customer.workspaces.businesses.calendar.appointments.show'] as $name) {
            $this->assertNotNull(Route::getRoutes()->getByName($name));
        }
    }

    public function test_no_external_provider_sync_was_started(): void
    {
        $this->assertFalse(class_exists(\App\Console\Commands\SyncExternalCalendars::class));
    }
}
