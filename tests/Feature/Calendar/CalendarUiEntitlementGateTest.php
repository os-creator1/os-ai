<?php

namespace Tests\Feature\Calendar;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §6, §11, §12.D, §13 proof 8 (first half) — every
 * authenticated Calendar route Sub-slice D adds fails closed with 404 while
 * `PlatformFeature::Calendar` is `Planned`, for an actor who is otherwise
 * fully authorized.
 *
 * NOTHING HERE IS SEEDED OR BYPASSED. This file deliberately never calls
 * entitledCalendar(): it exercises the real, unmodified Planned enforcement,
 * which is the property Sub-slice D must preserve. "Otherwise fully
 * authorized" is set up on purpose — the Workspace and Business are active, a
 * Core plan is assigned (Calendar is already packaged in core/growth/agency),
 * the actor owns both, and the Location is a real active Location of that
 * Business — so the ONLY reason these routes 404 is the entitlement gate.
 *
 * The second half of proof 8 ("the same request succeeds after the flip")
 * belongs to Sub-slice E, which owns the Planned -> Available flip.
 */
class CalendarUiEntitlementGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
    }

    /** @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> */
    private function d15Routes(string $locationUid): array
    {
        $scope = [$this->workspace->uid, $this->business->uid, $locationUid];
        $business = [$this->workspace->uid, $this->business->uid];
        $withAppointment = array_merge($scope, ['any-appointment-uid']);

        return [
            ['get', 'index', $business],
            ['get', 'schedule', $scope],
            ['get', 'appointments.create', $scope],
            ['post', 'appointments.store', $scope],
            ['get', 'appointments.show', $withAppointment],
            ['post', 'appointments.reschedule', $withAppointment],
            ['post', 'appointments.cancel', $withAppointment],
            ['post', 'appointments.complete', $withAppointment],
            ['post', 'appointments.no-show', $withAppointment],
        ];
    }

    private function assertEveryRouteIsNotFound(string $locationUid): void
    {
        foreach ($this->d15Routes($locationUid) as [$verb, $name, $args]) {
            $this->{$verb}($this->calendarUrl($name, $args))->assertNotFound();
        }
    }

    public function test_calendar_is_still_planned(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::Calendar->value));
    }

    public function test_every_new_route_is_registered(): void
    {
        foreach ($this->d15Routes($this->locationA->uid) as [, $name]) {
            $this->assertNotNull(
                Route::getRoutes()->getByName('customer.workspaces.businesses.calendar.' . $name),
                "Route [{$name}] is not registered — the gate test would pass vacuously."
            );
        }
    }

    public function test_every_new_route_is_not_found_for_the_account_owner(): void
    {
        $this->authenticate($this->owner->user);

        $this->assertEveryRouteIsNotFound($this->locationA->uid);
    }

    public function test_every_new_route_is_not_found_for_an_admin_and_for_staff(): void
    {
        foreach ([$this->admin, $this->staff] as $actor) {
            $this->authenticate($actor);

            $this->assertEveryRouteIsNotFound($this->locationA->uid);
        }
    }

    public function test_every_new_route_is_not_found_for_a_user_outside_the_workspace(): void
    {
        $this->authenticate($this->outsider);

        $this->assertEveryRouteIsNotFound($this->locationA->uid);
    }

    public function test_every_new_route_is_not_found_for_an_ungranted_location(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        $this->assertEveryRouteIsNotFound($this->locationB->uid);
    }

    /**
     * A forged POST changes nothing: no appointment row is written and no
     * engine lock row is created.
     */
    public function test_a_forged_post_writes_nothing_while_calendar_is_planned(): void
    {
        $this->authenticate($this->owner->user);

        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $this->post($this->calendarUrl('appointments.store', $this->scopeFor($this->locationA)), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => 'x',
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertNotFound();

        $this->assertSame(0, DB::table('appointments')->count());
        $this->assertSame(0, DB::table('staff_booking_locks')->count());
    }

    /**
     * §12.D: 'calendar' joins ENTITLEMENT_GATED_FEATURES. That list is NAV
     * gating — it hides the entry and lets the trait read the shell's
     * per-request snapshot — and is never itself the security gate.
     */
    public function test_calendar_is_now_a_nav_gated_feature(): void
    {
        $this->assertContains(PlatformFeature::Calendar->value, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
    }

    /**
     * §12.D — the nav entry is offered only when entitled. Unseeded and Planned,
     * an owner viewing a real page must not be shown a link to a surface that
     * would 404 for them.
     */
    public function test_the_calendar_nav_entry_is_absent_while_planned(): void
    {
        $this->authenticate($this->owner->user);

        $html = (string) $this->get(route('customer.workspaces.businesses.settings.show', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]),
            $html
        );
    }

    /**
     * Adding the key to the gated list must not have weakened enforcement: the
     * trait now takes the snapshot path for Calendar, and that path still
     * answers "no" while Planned.
     */
    public function test_the_snapshot_path_still_refuses_calendar_while_planned(): void
    {
        $this->authenticate($this->owner->user);

        $this->get($this->calendarUrl('schedule', $this->scopeFor($this->locationA)))->assertNotFound();
        $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))->assertNotFound();
    }
}
