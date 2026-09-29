<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Calendar visual-completion pass — focused contract tests for the markers
 * this pass introduced (module shell, subnav, toolbar, collapsible
 * fallback agenda). Deliberately narrow: existing suites
 * (CalendarScheduleAccessTest, CalendarAppointmentActionsTest,
 * CalendarUiEntitlementGateTest, BookingTypeManagementTest,
 * StaffAvailabilityAuthorityTest, PublicBookingTest) already prove every
 * authorization/route/booking-engine behavior is unchanged — this file
 * proves only the new presentation contract, and touches no route,
 * middleware, or write path.
 */
class CalendarModernUiContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
    }

    /** A booked Monday 10:00 America/New_York appointment at $location. Mirrors CalendarScheduleAccessTest's own helper. */
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

    // -----------------------------------------------------------------
    // Module shell / subnav
    // -----------------------------------------------------------------

    public function test_the_schedule_page_renders_the_module_header_and_marks_calendar_view_active(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->scheduleUrl($this->locationA))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="calendar-module-header"', $html);
        $this->assertStringContainsString('calendar-subnav-link is-active', $html);
        $this->assertStringContainsString('Calendar view', $html);
        $this->assertStringContainsString('Booking types', $html);
        $this->assertStringContainsString('Staff availability', $html);
    }

    public function test_booking_types_and_availability_pages_share_the_same_module_subnav_with_the_correct_tab_active(): void
    {
        $this->authenticate($this->owner->user);
        $scope = $this->scopeFor($this->locationA);

        $bookingTypesHtml = $this->get($this->calendarUrl('booking-types.index', $scope))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="calendar-module-header"', $bookingTypesHtml);
        $this->assertStringContainsString('calendar-subnav-link is-active', $bookingTypesHtml);
        $this->assertStringContainsString('Booking types', $bookingTypesHtml);

        $availabilityHtml = $this->get($this->calendarUrl('availability.index', $scope))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="calendar-module-header"', $availabilityHtml);
    }

    public function test_the_subnav_links_between_all_three_calendar_surfaces_resolve_to_the_real_authorized_routes(): void
    {
        $this->authenticate($this->owner->user);
        $scope = $this->scopeFor($this->locationA);

        $html = $this->get($this->scheduleUrl($this->locationA))->assertOk()->getContent();

        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.schedule', $scope), $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.booking-types.index', $scope), $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.availability.index', $scope), $html);
    }

    // -----------------------------------------------------------------
    // Toolbar
    // -----------------------------------------------------------------

    public function test_the_toolbar_carries_today_prev_next_view_switch_and_new_appointment_controls(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'week', 'date' => '2027-03-01']))
            ->assertOk()
            ->getContent();

        foreach (['calendar-today', 'calendar-prev', 'calendar-next', 'calendar-range', 'view-day', 'view-week', 'new-appointment'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, "missing data-role=\"{$role}\"");
        }
    }

    public function test_the_active_day_week_control_carries_aria_current(): void
    {
        $this->authenticate($this->owner->user);

        $dayHtml = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="view-day"[^>]*aria-current="page"/', $dayHtml);
        $this->assertDoesNotMatchRegularExpression('/data-role="view-week"[^>]*aria-current="page"/', $dayHtml);

        $weekHtml = $this->get($this->scheduleUrl($this->locationA, ['view' => 'week']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="view-week"[^>]*aria-current="page"/', $weekHtml);
    }

    public function test_the_new_appointment_toolbar_control_links_to_the_create_route_prefilled_with_the_anchor_date(): void
    {
        $this->authenticate($this->owner->user);
        $scope = $this->scopeFor($this->locationA);

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-05']))
            ->assertOk()
            ->getContent();

        $createUrl = route('customer.workspaces.businesses.calendar.appointments.create', $scope);
        $this->assertStringContainsString($createUrl . '?date=2027-03-05', $html);
    }

    // -----------------------------------------------------------------
    // FullCalendar mount / hooks (unchanged contract, still present)
    // -----------------------------------------------------------------

    public function test_the_calendar_mount_still_carries_its_data_hooks_and_a_correct_local_today_marker(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'week', 'date' => '2027-03-01']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-role="calendar-grid"', $html);
        $this->assertStringContainsString('data-view="week"', $html);
        $this->assertStringContainsString('data-date="2027-03-01"', $html);
        $this->assertMatchesRegularExpression('/data-today="\d{4}-\d{2}-\d{2}"/', $html);
        $this->assertStringContainsString("timeZone: 'UTC'", $html);
        $this->assertStringContainsString('vendors/js/calendar/fullcalendar.min.js', $html);
        // The documented resize/reflow bug guard: `expandRows` must never
        // appear as an ACTIVE config property (only in the explanatory
        // comment warning against re-adding it).
        $this->assertDoesNotMatchRegularExpression('/\n\s*expandRows\s*:\s*true\s*,/', $html);
    }

    // -----------------------------------------------------------------
    // Collapsible server-rendered fallback agenda
    // -----------------------------------------------------------------

    public function test_the_fallback_agenda_is_present_as_a_native_collapsible_details_element(): void
    {
        $this->authenticate($this->owner->user);
        $this->appointmentAt($this->locationA, '10:00:00');

        $html = $this->get($this->scheduleUrl($this->locationA, ['view' => 'day', 'date' => '2027-03-01']))
            ->assertOk()
            ->getContent();

        // A <details> fallback needs no JavaScript to be present in the
        // response and discoverable — only to be visually expanded.
        $this->assertMatchesRegularExpression('/<details[^>]*data-section="calendar-agenda"/', $html);
        $this->assertStringContainsString('data-role="calendar-agenda"', $html);
    }

    public function test_the_hint_text_appears_without_dominating_the_page(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->scheduleUrl($this->locationA))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="calendar-hint"', $html);
        $this->assertStringContainsString('Click a time slot to create an appointment.', $html);
    }

    // -----------------------------------------------------------------
    // No security/route regression from this purely visual pass
    // -----------------------------------------------------------------

    public function test_a_foreign_locations_calendar_pages_are_still_404_after_the_visual_pass(): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $this->authenticate($this->owner->user);

        $this->get($this->calendarUrl('schedule', [$this->workspace->uid, $this->business->uid, $foreignLocation->uid]))
            ->assertNotFound();
        $this->get($this->calendarUrl('booking-types.index', [$this->workspace->uid, $this->business->uid, $foreignLocation->uid]))
            ->assertNotFound();
        $this->get($this->calendarUrl('availability.index', [$this->workspace->uid, $this->business->uid, $foreignLocation->uid]))
            ->assertNotFound();
    }
}
