<?php

namespace Tests\Feature\Calendar;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/** Contract 15 §13 proof 8, after the Planned to Available flip. */
class CalendarUiEntitlementGateTest extends TestCase
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
        return $this->calendarUrl('schedule', $this->scopeFor($location));
    }

    private function assertEveryRouteDenied($location, bool $includeBusinessIndex = true): void
    {
        $scope = $this->scopeFor($location);
        $business = [$this->workspace->uid, $this->business->uid];
        $appointment = array_merge($scope, ['unknown-appointment']);
        $routes = [
            ['get', 'schedule', $scope],
            ['get', 'appointments.create', $scope],
            ['post', 'appointments.store', $scope],
            ['get', 'appointments.show', $appointment],
            ['post', 'appointments.reschedule', $appointment],
            ['post', 'appointments.cancel', $appointment],
            ['post', 'appointments.complete', $appointment],
            ['post', 'appointments.no-show', $appointment],
        ];
        if ($includeBusinessIndex) {
            array_unshift($routes, ['get', 'index', $business]);
        }
        foreach ($routes as [$verb, $name, $args]) {
            $this->{$verb}($this->calendarUrl($name, $args))->assertNotFound();
        }
    }

    public function test_entitled_owner_reaches_calendar_schedule(): void
    {
        $this->authenticate($this->owner->user);
        $this->get($this->url($this->locationA))->assertOk()->assertSee('data-section="calendar-schedule"', false);
    }

    public function test_outsider_and_ungranted_member_are_refused(): void
    {
        $this->authenticate($this->outsider);
        $this->assertEveryRouteDenied($this->locationA);
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);
        $this->assertEveryRouteDenied($this->locationB, false);
    }

    public function test_unentitled_owner_is_refused_even_after_activation(): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->delete();
        $this->authenticate($this->owner->user);
        $this->assertEveryRouteDenied($this->locationA);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_forged_direct_appointment_post_cannot_write_through_ungranted_location(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);
        $this->post($this->calendarUrl('appointments.store', $this->scopeFor($this->locationB)), [
            'booking_type_uid' => 'forged', 'contact_uid' => 'forged',
            'staff' => 'auto', 'date' => '2027-03-01', 'time' => '10:00',
        ])->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
    }
}
