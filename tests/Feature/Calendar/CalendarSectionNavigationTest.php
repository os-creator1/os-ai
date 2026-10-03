<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Calendar polish lane — focused contract tests for the three changes it
 * made: (1) the tab router's section response, (2) the adaptive visible-day
 * range, (3) the FullCalendar stylesheet that gives the grid its lines.
 *
 * Deliberately narrow. Authorization, booking rules, availability and every
 * write path are proven elsewhere (CalendarScheduleAccessTest,
 * CalendarModernUiContractTest, BookingTypeManagementTest, ...) and none of
 * them is touched by this lane. What the browser does with the script —
 * the live swap, Back/Forward, the late-response race — is verified in a
 * real browser (see the lane report); the script's presence and its guards
 * are pinned here.
 */
class CalendarSectionNavigationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $route, $location = null, array $query = []): string
    {
        $url = $this->calendarUrl($route, $this->scopeFor($location ?? $this->locationA));

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> route, section key, marker */
    public static function tabs(): array
    {
        return [
            'calendar view' => ['schedule', 'schedule', 'data-section="calendar-schedule"'],
            'booking types' => ['booking-types.index', 'booking-types', 'data-section="booking-types-list"'],
            'staff availability' => ['availability.index', 'availability', 'data-section="availability-rules"'],
        ];
    }

    private function appointmentOn(string $localDateTime, ?string $typeName = null): Appointment
    {
        $staff = $this->bookableStaff($this->locationA);
        $type = $typeName === null ? $this->bookingType($this->locationA) : $this->bookingType($this->locationA, ['name' => $typeName]);

        return $this->engine()->book(
            $type,
            (int) $staff->id,
            $this->contactId(),
            Carbon::parse($localDateTime, 'America/New_York')->utc()
        );
    }

    private function eventsJson(string $html): string
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-role="calendar-events">(.*?)</script>#s', $html, $m), 'the events payload must be one inert JSON script');

        return $m[1];
    }

    // -----------------------------------------------------------------
    // Direct URLs: every tab is still a complete, ordinary page
    // -----------------------------------------------------------------

    #[DataProvider('tabs')]
    public function test_each_tab_url_renders_a_full_page_with_the_shell_and_exactly_one_content_region(string $route, string $key, string $marker): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url($route))->assertOk()->getContent();

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('id="main-content"', $html, 'the Business OS shell must still be rendered around the section');
        $this->assertStringContainsString('data-role="calendar-module-header"', $html);
        $this->assertSame(1, substr_count($html, 'id="calendar-content"'));
        $this->assertStringContainsString('data-calendar-section="' . $key . '"', $html);
        $this->assertStringContainsString($marker, $html);
        $this->assertSame(1, substr_count($html, 'window.CalendarSections = {'), 'the router script is emitted once per page load');
    }

    public function test_the_three_tab_links_are_real_links_the_router_can_take_over(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule'))->assertOk()->getContent();

        foreach (['schedule', 'booking-types', 'availability'] as $key) {
            $this->assertMatchesRegularExpression('#<a href="[^"]+"[^>]*data-calendar-nav data-calendar-key="' . $key . '"#s', $html);
        }

        // Real hrefs to the real authorized routes — they work with no script,
        // in a new tab, and for a middle-click.
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.booking-types.index', $this->scopeFor($this->locationA)), $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.availability.index', $this->scopeFor($this->locationA)), $html);
    }

    // -----------------------------------------------------------------
    // Section response (?fragment=1)
    // -----------------------------------------------------------------

    #[DataProvider('tabs')]
    public function test_a_fragment_request_returns_only_that_tabs_section(string $route, string $key, string $marker): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url($route, null, ['fragment' => '1']))->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('id="main-content"', $html);
        $this->assertStringNotContainsString('data-role="calendar-module-header"', $html);
        $this->assertStringNotContainsString('window.CalendarSections', $html, 'a swap must never re-run the router script');
        $this->assertSame(1, substr_count($html, 'id="calendar-content"'));
        $this->assertStringContainsString('data-calendar-section="' . $key . '"', $html);
        $this->assertMatchesRegularExpression('/data-calendar-title="[^"]+"/', $html);
        $this->assertStringContainsString($marker, $html);
    }

    #[DataProvider('tabs')]
    public function test_a_fragment_is_refused_exactly_like_the_page_for_a_foreign_location(string $route): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $this->authenticate($this->owner->user);

        // Same controller action, same gates: asking for "just the section"
        // is not a second read surface and reveals nothing the page would not.
        $this->get($this->url($route, $foreignLocation, ['fragment' => '1']))->assertNotFound();
        $this->get($this->url($route, $foreignLocation))->assertNotFound();
    }

    public function test_a_fragment_carries_the_same_events_and_range_as_the_full_page(): void
    {
        $this->authenticate($this->owner->user);
        $appointment = $this->appointmentOn('2027-03-01 10:00');
        $query = ['view' => 'week', 'date' => '2027-03-03'];

        $page = $this->get($this->url('schedule', null, $query))->assertOk()->getContent();
        $fragment = $this->get($this->url('schedule', null, $query + ['fragment' => '1']))->assertOk()->getContent();

        $this->assertSame($this->eventsJson($page), $this->eventsJson($fragment));
        $this->assertStringContainsString('"id":"' . $appointment->uid . '"', $this->eventsJson($fragment));
        $this->assertStringContainsString('1 Mar – 7 Mar 2027', $fragment);
    }

    public function test_events_are_inert_json_that_cannot_break_out_of_their_script_element(): void
    {
        $this->authenticate($this->owner->user);
        $this->appointmentOn('2027-03-01 10:00', '</script><img src=x onerror=alert(1)>');

        $html = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-01']))->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><img', $html);
        // "<" and ">" are hex-escaped, so no raw "</script" can occur in the payload.
        $escaped = trim(json_encode('</script>', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), '"');
        $this->assertStringContainsString($escaped, $this->eventsJson($html));
        $this->assertStringNotContainsString('</', $this->eventsJson($html));
    }

    // -----------------------------------------------------------------
    // Visible-day range
    // -----------------------------------------------------------------

    public function test_the_default_range_is_the_classic_monday_week_and_links_stay_clean(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-03']))->assertOk()->getContent();

        $this->assertStringContainsString('data-days="7"', $html);
        $this->assertStringContainsString('data-range-start="2027-03-01"', $html);
        $this->assertStringContainsString('1 Mar – 7 Mar 2027', $html);
        $this->assertDoesNotMatchRegularExpression('/(\?|&amp;|&)days=/', $html, 'the everyday 7-day URL carries no days parameter');
    }

    public function test_a_fourteen_day_range_is_monday_aligned_and_navigation_moves_by_fourteen_days(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-03', 'days' => 14]))->assertOk()->getContent();

        $this->assertStringContainsString('data-days="14"', $html);
        $this->assertStringContainsString('data-range-start="2027-03-01"', $html);
        $this->assertStringContainsString('1 Mar – 14 Mar 2027', $html);
        $this->assertStringContainsString('date=2027-02-17&amp;days=14', $html);
        $this->assertStringContainsString('date=2027-03-17&amp;days=14', $html);
    }

    public function test_a_ten_day_range_starts_exactly_on_its_date_and_navigation_moves_by_ten_days(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-03', 'days' => 10]))->assertOk()->getContent();

        $this->assertStringContainsString('data-days="10"', $html);
        $this->assertStringContainsString('data-range-start="2027-03-03"', $html);
        $this->assertStringContainsString('3 Mar – 12 Mar 2027', $html);
        $this->assertStringContainsString('date=2027-02-21&amp;days=10', $html);
        $this->assertStringContainsString('date=2027-03-13&amp;days=10', $html);
    }

    public function test_only_7_10_and_14_are_accepted_anything_else_is_the_classic_week(): void
    {
        $this->authenticate($this->owner->user);

        foreach (['9', '15', '0', '-7', '1', 'abc', '14abc', ''] as $bad) {
            $html = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-03', 'days' => $bad]))->assertOk()->getContent();

            $this->assertStringContainsString('data-days="7"', $html, "days={$bad}");
            $this->assertStringContainsString('1 Mar – 7 Mar 2027', $html, "days={$bad}");
        }

        $array = $this->get($this->url('schedule') . '?view=week&date=2027-03-03&days[]=14')->assertOk()->getContent();
        $this->assertStringContainsString('data-days="7"', $array);
    }

    public function test_the_day_view_ignores_the_days_parameter_and_steps_by_one_day(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'day', 'date' => '2027-03-01', 'days' => 14]))->assertOk()->getContent();

        $this->assertStringContainsString('data-days="1"', $html);
        $this->assertStringContainsString('Monday 1 March 2027', $html);
        $this->assertStringContainsString('date=2027-02-28', $html);
        $this->assertStringContainsString('date=2027-03-02', $html);
    }

    public function test_the_range_reads_appointments_across_its_whole_span(): void
    {
        $this->authenticate($this->owner->user);
        $secondMonday = $this->appointmentOn('2027-03-08 10:00');

        $seven = $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-01']))->assertOk();
        $seven->assertDontSee('data-appointment="' . $secondMonday->uid . '"', false);

        $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-01', 'days' => 14]))
            ->assertOk()
            ->assertSee('data-appointment="' . $secondMonday->uid . '"', false);

        // 10 days from Mon 1 Mar reaches Wed 10 Mar, so Mon 8 Mar is inside.
        $this->get($this->url('schedule', null, ['view' => 'week', 'date' => '2027-03-01', 'days' => 10]))
            ->assertOk()
            ->assertSee('data-appointment="' . $secondMonday->uid . '"', false);
    }

    public function test_a_ten_day_range_with_no_date_opens_on_this_weeks_monday_and_today_returns_there(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-04 12:00:00', 'America/New_York'));
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'week', 'days' => 10]))->assertOk()->getContent();

        $this->assertStringContainsString('data-range-start="2027-03-01"', $html);
        $this->assertStringContainsString('1 Mar – 10 Mar 2027', $html);
        // "Today" for a span that is not whole weeks is the start of this week...
        $this->assertMatchesRegularExpression('/data-role="calendar-today"[^>]*href="[^"]*date=2027-03-01&amp;days=10"|href="[^"]*date=2027-03-01&amp;days=10"[^>]*data-role="calendar-today"/', $html);
        // ...while "Day" lands on the real today (Thu 4 Mar) because it is inside the range.
        $this->assertStringContainsString('view=day&amp;date=2027-03-04', $html);
    }

    public function test_the_classic_week_keeps_todays_date_on_its_today_control(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-04 12:00:00', 'America/New_York'));
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule', null, ['view' => 'week']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*date=2027-03-04"[^>]*data-role="calendar-today"|data-role="calendar-today"[^>]*href="[^"]*date=2027-03-04"/', $html);
    }

    // -----------------------------------------------------------------
    // The router script's guards (behaviour itself: real-browser verified)
    // -----------------------------------------------------------------

    public function test_the_router_script_carries_its_stale_response_history_and_fallback_guards(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('booking-types.index'))->assertOk()->getContent();

        // last click wins: each navigation aborts the previous request and
        // discards any response that is no longer the latest.
        $this->assertStringContainsString('new AbortController()', $html);
        $this->assertStringContainsString('inflight.abort()', $html);
        $this->assertStringContainsString('ticket !== generation', $html);
        // real URLs, working Back/Forward.
        $this->assertStringContainsString('window.history.pushState', $html);
        $this->assertStringContainsString("addEventListener('popstate'", $html);
        // any failure falls back to ordinary navigation.
        $this->assertStringContainsString('window.location.assign(target)', $html);
        // only plain left-clicks are intercepted, so new-tab/middle-click keep working.
        $this->assertStringContainsString('event.metaKey || event.ctrlKey', $html);
        // FullCalendar is loaded on demand when the first tab did not ship it.
        $this->assertStringContainsString('data-fc-js=', $html);
        $this->assertStringContainsString('data-fc-css=', $html);
    }

    // -----------------------------------------------------------------
    // The grid lines (root cause: FullCalendar's stylesheet was never loaded)
    // -----------------------------------------------------------------

    public function test_the_schedule_links_fullcalendars_own_stylesheet_which_the_vendored_script_does_not_inject(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule'))->assertOk()->getContent();

        $this->assertStringContainsString('vendors/css/calendars/fullcalendar.min.css', $html);
        $this->assertFileExists(public_path('vendors/css/calendars/fullcalendar.min.css'));
        $this->assertStringContainsString('.fc-theme-standard td', (string) file_get_contents(public_path('vendors/css/calendars/fullcalendar.min.css')));
    }

    public function test_the_grid_line_colour_is_the_visible_border_token_not_the_near_white_subtle_one(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule'))->assertOk()->getContent();

        $this->assertStringContainsString('--fc-border-color: var(--color-border, #E5E1DA);', $html);
        $this->assertStringNotContainsString('--fc-border-color: var(--color-border-subtle', $html);
        $this->assertStringContainsString('.fc-timegrid-col {', $html);
    }
}
