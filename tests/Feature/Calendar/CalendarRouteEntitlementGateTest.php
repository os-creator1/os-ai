<?php

namespace Tests\Feature\Calendar;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/** Contract 15B routes retain tenancy, Location and plan gates after activation. */
class CalendarRouteEntitlementGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
    }

    private function url($location): string
    {
        return $this->calendarUrl('booking-types.index', $this->scopeFor($location));
    }

    private function assertEveryRouteDenied($location): void
    {
        $scope = $this->scopeFor($location);
        $withType = array_merge($scope, ['unknown-booking-type']);
        foreach ([
            ['get', 'booking-types.index', $scope],
            ['get', 'booking-types.create', $scope],
            ['post', 'booking-types.store', $scope],
            ['get', 'booking-types.edit', $withType],
            ['post', 'booking-types.update', $withType],
            ['post', 'booking-types.active', $withType],
            ['post', 'booking-types.staff', $withType],
            ['get', 'availability.index', $scope],
            ['post', 'availability.rules.store', $scope],
            ['post', 'availability.rules.destroy', array_merge($scope, [1])],
            ['post', 'availability.time-off.store', $scope],
            ['post', 'availability.time-off.destroy', array_merge($scope, [1])],
        ] as [$verb, $name, $args]) {
            $this->{$verb}($this->calendarUrl($name, $args))->assertNotFound();
        }
    }

    public function test_entitled_owner_reaches_booking_type_configuration(): void
    {
        $this->authenticate($this->owner->user);
        $this->get($this->url($this->locationA))->assertOk();
    }

    public function test_outsider_and_ungranted_member_are_refused(): void
    {
        $this->authenticate($this->outsider);
        $this->assertEveryRouteDenied($this->locationA);
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);
        $this->assertEveryRouteDenied($this->locationB);
    }

    public function test_unentitled_workspace_is_refused(): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->delete();
        $this->authenticate($this->owner->user);
        $this->assertEveryRouteDenied($this->locationA);
    }
}
