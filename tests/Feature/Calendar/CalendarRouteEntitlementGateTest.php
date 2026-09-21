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
        $this->assertDatabaseCount('booking_types', 0);
        $this->assertDatabaseCount('staff_availability_rules', 0);
    }

    public function test_forged_direct_posts_cannot_write_through_ungranted_location(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);
        $scope = $this->scopeFor($this->locationB);
        $this->post($this->calendarUrl('booking-types.store', $scope), [
            'name' => 'Forged', 'duration_minutes' => 30,
        ])->assertNotFound();
        $this->post($this->calendarUrl('availability.rules.store', $scope), [
            'staff_user_id' => $restricted->id, 'day_of_week' => 1,
            'start_time' => '09:00', 'end_time' => '17:00',
        ])->assertNotFound();
        $this->assertDatabaseCount('booking_types', 0);
        $this->assertDatabaseCount('staff_availability_rules', 0);
    }
}
