<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §6, §12.D — the day/week schedule, the Location
 * picker, and the Location ACL matrix around them.
 *
 * Runs with the entitlement ANSWER supplied (entitledCalendar()) and nothing
 * else: Workspace/Business tenancy and LocationAccessGuard are the real
 * production code, so every "absent" and "404" below is the genuine ACL
 * refusing, not a fixture shortcut. The Planned enforcement itself is proven,
 * unseeded, by CalendarUiEntitlementGateTest.
 */
class CalendarScheduleAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
    }

    /** A booked Monday 10:00 America/New_York appointment at $location. */
    private function appointmentAt($location, ?string $time = '10:00:00'): Appointment
    {
        $staff = $this->bookableStaff($location);

        return $this->engine()->book(
            $this->bookingType($location),
            (int) $staff->id,
            $this->contactId(),
            $this->slotStart($time)
        );
    }

    private function scheduleUrl($location, array $query = []): string
    {
        $url = $this->calendarUrl('schedule', $this->scopeFor($location));

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    // --- the seam works at all (guards against a vacuous suite) ---

    public function test_the_seeded_entitlement_lets_an_authorized_owner_reach_the_schedule(): void
    {
        $this->authenticate($this->owner->user);

        $this->get($this->scheduleUrl($this->locationA))
            ->assertOk()
            ->assertSee('data-section="calendar-schedule"', false);
    }

    // --- day / week views ---

    public function test_the_week_view_shows_an_appointment_in_that_week_and_the_day_view_narrows_it(): void
    {
        $this->authenticate($this->owner->user);
        $appointment = $this->appointmentAt($this->locationA);

        // Monday 2027-03-01: in the week of 1 March, on the day 1 March.
        $this->get($this->scheduleUrl($this->locationA, ['view' => 'week', 'date' => '2027-03-03']))
            ->assertOk()
            ->assertSee('data-appointment="' . $appointment->uid . '"', false);

        $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))
            ->assertOk()
            ->assertSee('data-appointment="' . $appointment->uid . '"', false);

        // Tuesday: same week, but not the same day.
        $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-02']))
            ->assertOk()
            ->assertDontSee('data-appointment="' . $appointment->uid . '"', false)
            ->assertSee('data-role="calendar-empty"', false);
    }

    public function test_an_appointment_in_another_week_is_not_shown(): void
    {
        $this->authenticate($this->owner->user);
        $appointment = $this->appointmentAt($this->locationA);

        $this->get($this->scheduleUrl($this->locationA, ['view' => 'week', 'date' => '2027-03-10']))
            ->assertOk()
            ->assertDontSee('data-appointment="' . $appointment->uid . '"', false);
    }

    /**
     * Times render in the BUSINESS's timezone, not UTC and not the browser's.
     * 10:00 New York is 15:00 UTC in March 2027, so the agenda must say 10:00.
     */
    public function test_times_render_in_the_business_timezone(): void
    {
        $this->authenticate($this->owner->user);
        $this->appointmentAt($this->locationA, '10:00:00');

        $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))
            ->assertOk()
            ->assertSee('10:00–11:00', false)
            ->assertDontSee('15:00–16:00', false)
            ->assertSee('America/New_York');
    }

    /**
     * The FullCalendar events carry LOCAL wall-clock with no offset, because
     * the vendored v5.7.2 build supports only 'local' and 'UTC' without a
     * timezone plugin and the page runs it in 'UTC' to show them verbatim.
     */
    public function test_calendar_events_carry_business_local_wall_clock_without_an_offset(): void
    {
        $this->authenticate($this->owner->user);
        $this->appointmentAt($this->locationA, '10:00:00');

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"start":"2027-03-01T10:00:00"', $html);
        $this->assertStringContainsString('"end":"2027-03-01T11:00:00"', $html);
        $this->assertStringContainsString("timeZone: 'UTC'", $html);
        $this->assertStringContainsString('vendors/js/calendar/fullcalendar.min.js', $html);
    }

    public function test_an_invalid_view_or_date_falls_back_rather_than_erroring(): void
    {
        $this->authenticate($this->owner->user);

        $this->get($this->scheduleUrl($this->locationA, ['view' => 'month', 'date' => 'not-a-date']))
            ->assertOk()
            ->assertSee('data-view="week"', false);
    }

    public function test_navigation_links_move_by_the_view_span(): void
    {
        $this->authenticate($this->owner->user);

        $day = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))->getContent();
        $this->assertStringContainsString('date=2027-02-28', $day);
        $this->assertStringContainsString('date=2027-03-02', $day);

        $week = $this->get($this->scheduleUrl($this->locationA, ['view' => 'week', 'date' => '2027-03-01']))->getContent();
        $this->assertStringContainsString('date=2027-02-22', $week);
        $this->assertStringContainsString('date=2027-03-08', $week);
    }

    /** DST: the local day of 14 March 2027 (spring forward) still contains a late appointment. */
    public function test_a_dst_day_is_covered_by_local_calendar_arithmetic(): void
    {
        $this->authenticate($this->owner->user);

        $staff = $this->bookableStaff($this->locationA);
        // Sunday needs its own window; give the staff member one.
        \App\Models\StaffAvailabilityRule::create([
            'business_location_id' => $this->locationA->id,
            'staff_user_id' => $staff->id,
            'day_of_week' => 0,
            'start_time' => '08:00:00',
            'end_time' => '23:00:00',
        ]);

        $appointment = $this->engine()->book(
            $this->bookingType($this->locationA),
            (int) $staff->id,
            $this->contactId(),
            Carbon::parse('2027-03-14 20:00:00', 'America/New_York')->utc()
        );

        $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-14']))
            ->assertOk()
            ->assertSee('data-appointment="' . $appointment->uid . '"', false);
    }

    // --- Location ACL ---

    public function test_an_inaccessible_location_is_404_and_renders_nothing(): void
    {
        $this->appointmentAt($this->locationB);

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        $this->get($this->scheduleUrl($this->locationB, ['view' => 'week', 'date' => '2027-03-01']))->assertNotFound();
        $this->get($this->calendarUrl('appointments.create', $this->scopeFor($this->locationB)))->assertNotFound();
    }

    /**
     * Addendum §4: a guessed appointment id never bypasses Location ACL. The
     * actor can reach Location A, guesses an appointment uid that lives at
     * Location B, and receives exactly what a nonexistent uid would.
     */
    public function test_a_guessed_appointment_uid_from_an_inaccessible_location_is_refused(): void
    {
        $hidden = $this->appointmentAt($this->locationB);

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        // Through the location the actor CAN reach: the uid does not resolve there.
        $this->get($this->calendarUrl('appointments.show', array_merge($this->scopeFor($this->locationA), [$hidden->uid])))
            ->assertNotFound();

        // Through the location it really lives at: the Location itself is refused.
        $this->get($this->calendarUrl('appointments.show', array_merge($this->scopeFor($this->locationB), [$hidden->uid])))
            ->assertNotFound();

        foreach (['reschedule', 'cancel', 'complete', 'no-show'] as $action) {
            $this->post($this->calendarUrl('appointments.' . $action, array_merge($this->scopeFor($this->locationA), [$hidden->uid])), [
                'date' => '2027-03-01', 'time' => '12:00',
            ])->assertNotFound();
        }

        $this->assertSame('scheduled', $hidden->fresh()->status->value, 'A guessed uid must not mutate anything.');
    }

    /** The refusal must be indistinguishable from a uid that simply does not exist. */
    public function test_a_hidden_appointment_and_a_nonexistent_one_are_indistinguishable(): void
    {
        $hidden = $this->appointmentAt($this->locationB);

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        $real = $this->get($this->calendarUrl('appointments.show', array_merge($this->scopeFor($this->locationA), [$hidden->uid])));
        $fake = $this->get($this->calendarUrl('appointments.show', array_merge($this->scopeFor($this->locationA), ['00000000-0000-0000-0000-000000000000'])));

        $this->assertSame($fake->getStatusCode(), $real->getStatusCode());
    }

    public function test_the_schedule_never_renders_or_counts_a_sibling_locations_appointments(): void
    {
        $atA = $this->appointmentAt($this->locationA, '10:00:00');
        $atB = $this->appointmentAt($this->locationB, '10:00:00');

        $this->authenticate($this->owner->user);

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($atA->uid, $html);
        $this->assertStringNotContainsString($atB->uid, $html, 'Location B\'s appointment leaked into Location A\'s view.');
        $this->assertSame(1, substr_count($html, 'data-appointment="'), 'Exactly one appointment row: no cross-Location count.');
    }

    public function test_a_foreign_business_location_is_not_reachable_through_this_business(): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $this->authenticate($this->owner->user);

        $this->get($this->calendarUrl('schedule', [$this->workspace->uid, $this->business->uid, $foreignLocation->uid]))
            ->assertNotFound();
    }

    // --- Location picker ---

    /** Two accessible Locations: a picker listing exactly those. */
    public function test_the_picker_lists_every_accessible_location(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()
            ->assertSee('data-section="calendar-location-picker"', false)
            ->getContent();

        $this->assertStringContainsString('Location A', $html);
        $this->assertStringContainsString('Location B', $html);
        $this->assertSame(2, substr_count($html, 'data-role="calendar-location"'));
    }

    /**
     * The picker must not disclose a Location the actor cannot reach — by name,
     * by link, or by count. With exactly one accessible Location there is
     * nothing to pick, so it redirects straight there.
     */
    public function test_a_single_accessible_location_skips_the_picker_and_discloses_nothing_else(): void
    {
        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);

        $response = $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]));

        $response->assertRedirect($this->calendarUrl('schedule', $this->scopeFor($this->locationA)));

        $page = $this->followingRedirects()
            ->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))
            ->getContent();

        $this->assertStringContainsString('Location A', $page);
        $this->assertStringNotContainsString('Location B', $page, 'An inaccessible Location\'s name leaked.');
        $this->assertStringNotContainsString($this->locationB->uid, $page, 'An inaccessible Location\'s uid leaked.');
        $this->assertStringNotContainsString('data-role="change-location"', $page, 'A "change location" link implies a sibling exists.');
    }

    public function test_the_picker_shows_only_the_locations_a_partially_granted_member_can_reach(): void
    {
        $third = $this->makeLocation($this->business, ['name' => 'Location C']);
        $restricted = $this->memberGrantedOnly($this->locationA);
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign(
            app(\App\Repositories\Contracts\WorkspaceMembershipRepository::class)
                ->findByWorkspaceAndUser($this->workspace, (int) $restricted->id),
            $third
        );

        $this->authenticate($restricted);

        $html = $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Location A', $html);
        $this->assertStringContainsString('Location C', $html);
        $this->assertStringNotContainsString('Location B', $html);
        $this->assertSame(2, substr_count($html, 'data-role="calendar-location"'));
    }

    public function test_a_user_with_no_accessible_location_gets_404_not_an_empty_picker(): void
    {
        $this->authenticate($this->outsider);

        $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))->assertNotFound();
    }

    /** An archived Location has no calendar and is not offered. */
    public function test_an_archived_location_is_not_offered(): void
    {
        \Illuminate\Support\Facades\DB::table('business_locations')
            ->where('id', $this->locationB->id)
            ->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $this->authenticate($this->owner->user);

        $this->get($this->calendarUrl('index', [$this->workspace->uid, $this->business->uid]))
            ->assertRedirect($this->calendarUrl('schedule', $this->scopeFor($this->locationA)));
    }

    /** The "change location" affordance appears only when a sibling is actually reachable. */
    public function test_change_location_appears_only_when_a_sibling_is_accessible(): void
    {
        $this->authenticate($this->owner->user);
        $this->get($this->scheduleUrl($this->locationA))
            ->assertOk()
            ->assertSee('data-role="change-location"', false);

        $restricted = $this->memberGrantedOnly($this->locationA);
        $this->authenticate($restricted);
        $this->get($this->scheduleUrl($this->locationA))
            ->assertOk()
            ->assertDontSee('data-role="change-location"', false);
    }

    // --- nav ---

    public function test_the_calendar_nav_entry_is_offered_when_entitled(): void
    {
        $this->authenticate($this->owner->user);

        $this->get($this->scheduleUrl($this->locationA))
            ->assertOk()
            ->assertSee(route('customer.workspaces.businesses.calendar.index', [$this->workspace->uid, $this->business->uid]), false);
    }
}
