<?php

namespace Tests\Feature\Calendar;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\StaffAvailabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * UI polish lane — the weekly-hours editor on Staff availability, its
 * "Use business hours" starting point, and the header cleanup that shares
 * this lane. The editor writes the SAME StaffAvailabilityRule rows the
 * per-window endpoints do, behind the same authority; those endpoints and
 * their tests (StaffAvailabilityAuthorityTest and the V1 acceptance journey)
 * are unchanged.
 */
class AvailabilityEditorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
    }

    private function url(string $route, array $query = [], $location = null): string
    {
        $url = $this->calendarUrl($route, $this->scopeFor($location ?? $this->locationA));

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** @return array<int, array{0: string, 1: string}> day_of_week => [start, end] windows as "H:i-H:i" strings */
    private function rulesFor(int $staffId, $location = null): array
    {
        return StaffAvailabilityRule::query()
            ->where('business_location_id', ($location ?? $this->locationA)->id)
            ->where('staff_user_id', $staffId)
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get()
            ->map(fn ($r) => (int) $r->day_of_week . ' ' . substr($r->start_time, 0, 5) . '-' . substr($r->end_time, 0, 5))
            ->all();
    }

    private function week(array $open, array $days): array
    {
        return ['open' => $open, 'days' => $days];
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    public function test_the_page_shows_seven_day_rows_prefilled_from_the_persons_rules(): void
    {
        $staff = $this->bookableStaff($this->locationA); // Mon 08:00-20:00
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('availability.index', ['person' => $staff->id]))->assertOk()->getContent();

        $this->assertSame(7, substr_count($html, 'data-role="availability-day"'));
        $this->assertStringContainsString('data-day-toggle', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.calendar.availability.week.update', $this->scopeFor($this->locationA)), $html);
        $this->assertStringContainsString('name="days[1][0][start]" value="08:00"', $html);
        $this->assertStringContainsString('name="days[1][0][end]" value="20:00"', $html);
        // Only Monday is open: the other six rows render closed.
        $this->assertSame(6, substr_count($html, 'availability-day is-closed'));
        $this->assertStringContainsString('data-section="availability-rules"', $html);
    }

    public function test_the_editor_is_part_of_the_section_fragment(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('availability.index', ['fragment' => '1']))->assertOk()->getContent();

        $this->assertStringContainsString('data-availability-editor', $html);
        $this->assertStringNotContainsString('<html', $html);
    }

    public function test_an_owner_can_choose_whose_week_to_edit_and_is_offered_only_manageable_people(): void
    {
        $first = $this->bookableStaff($this->locationA);
        $second = $this->bookableStaff($this->locationA);
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('availability.index', ['person' => $second->id]))->assertOk()->getContent();

        $this->assertStringContainsString('id="availability_person"', $html);
        $this->assertMatchesRegularExpression('/<input type="hidden" name="staff_user_id" value="' . $second->id . '">/', $html);
        $this->assertStringContainsString('value="' . $first->id . '"', $html);
    }

    // -----------------------------------------------------------------
    // Save (replaceWeek)
    // -----------------------------------------------------------------

    public function test_saving_replaces_the_persons_week_with_open_closed_and_split_days(): void
    {
        $staff = $this->bookableStaff($this->locationA); // existing Monday 08:00-20:00
        $otherStaff = $this->bookableStaff($this->locationA);
        $this->authenticate($this->owner->user);

        $this->post($this->url('availability.week.update'), [
            'staff_user_id' => $staff->id,
            'open' => [1 => '1', 2 => '1', 6 => '1'],
            'days' => [
                1 => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:30']],
                2 => [['start' => '10:00', 'end' => '16:00']],
                3 => [['start' => '09:00', 'end' => '17:00']], // switch is off: must be ignored
                6 => [['start' => '09:00', 'end' => '13:00']],
            ],
        ])->assertRedirect($this->url('availability.index', ['person' => $staff->id]))
            ->assertSessionHas('flash_success');

        $this->assertSame(['1 09:00-12:00', '1 13:00-17:30', '2 10:00-16:00', '6 09:00-13:00'], $this->rulesFor((int) $staff->id));
        $this->assertSame(['1 08:00-20:00'], $this->rulesFor((int) $otherStaff->id), 'another person\'s week is never touched');
    }

    public function test_switching_every_day_off_clears_the_week(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $this->authenticate($this->owner->user);

        $this->post($this->url('availability.week.update'), ['staff_user_id' => $staff->id])->assertRedirect();

        $this->assertSame([], $this->rulesFor((int) $staff->id));
    }

    public function test_saving_never_touches_the_same_persons_hours_at_another_location(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $this->giveMondayAvailability((int) $staff->id, $this->locationB);
        $this->authenticate($this->owner->user);

        $this->post($this->url('availability.week.update'), ['staff_user_id' => $staff->id])->assertRedirect();

        $this->assertSame([], $this->rulesFor((int) $staff->id, $this->locationA));
        $this->assertSame(['1 08:00-20:00'], $this->rulesFor((int) $staff->id, $this->locationB));
    }

    public function test_invalid_hours_are_refused_and_nothing_changes(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $this->authenticate($this->owner->user);
        $before = $this->rulesFor((int) $staff->id);

        $cases = [
            'closing before opening' => [1 => [['start' => '17:00', 'end' => '09:00']]],
            'equal times' => [1 => [['start' => '09:00', 'end' => '09:00']]],
            'overlapping windows' => [1 => [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '17:00']]],
            'open with no hours' => [1 => []],
            'half-filled window' => [1 => [['start' => '09:00', 'end' => '']]],
        ];

        foreach ($cases as $label => $days) {
            $this->post($this->url('availability.week.update'), ['staff_user_id' => $staff->id, 'open' => [1 => '1'], 'days' => $days])
                ->assertSessionHasErrors(null, null, $label);
            $this->assertSame($before, $this->rulesFor((int) $staff->id), $label);
        }
    }

    public function test_the_week_write_keeps_the_per_window_authority(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $other = $this->bookableStaff($this->locationA);
        $this->authenticate($staff);

        // A staff member may set their OWN week...
        $this->post($this->url('availability.week.update'), [
            'staff_user_id' => $staff->id,
            'open' => [2 => '1'],
            'days' => [2 => [['start' => '09:00', 'end' => '12:00']]],
        ])->assertRedirect();
        $this->assertSame(['2 09:00-12:00'], $this->rulesFor((int) $staff->id));

        // ...but never someone else's.
        $response = $this->post($this->url('availability.week.update'), [
            'staff_user_id' => $other->id,
            'open' => [2 => '1'],
            'days' => [2 => [['start' => '09:00', 'end' => '12:00']]],
        ]);
        $response->assertStatus(401);
        $this->assertSame(['1 08:00-20:00'], $this->rulesFor((int) $other->id));
    }

    public function test_a_non_owner_is_only_ever_shown_their_own_week(): void
    {
        $staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $other = $this->bookableStaff($this->locationA);
        $this->authenticate($staff);

        $html = $this->get($this->url('availability.index', ['person' => $other->id]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="staff_user_id" value="' . $staff->id . '">/', $html);
        $this->assertStringNotContainsString('id="availability_person"', $html);
    }

    public function test_a_foreign_location_cannot_be_written_through_the_editor(): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $this->authenticate($this->owner->user);

        $this->post($this->url('availability.week.update', [], $foreignLocation), ['staff_user_id' => $this->owner->user->id])->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Use business hours
    // -----------------------------------------------------------------

    public function test_use_business_hours_prefills_an_unsaved_week_and_writes_nothing(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        DB::table('business_locations')->where('id', $this->locationA->id)->update(['hours' => json_encode([
            'monday' => [['open' => '08:00', 'close' => '12:00'], ['open' => '13:00', 'close' => '18:00']],
            'friday' => [['open' => '09:00', 'close' => '24:00']],
            'saturday' => [],
        ])]);
        $this->authenticate($this->owner->user);
        $before = $this->rulesFor((int) $staff->id);

        $plain = $this->get($this->url('availability.index', ['person' => $staff->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="use-business-hours"', $plain);

        $html = $this->get($this->url('availability.index', ['person' => $staff->id, 'prefill' => 'business']))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="availability-prefill-note"', $html);
        $this->assertStringContainsString('name="days[1][0][start]" value="08:00"', $html);
        $this->assertStringContainsString('name="days[1][1][start]" value="13:00"', $html);
        $this->assertStringContainsString('name="days[5][0][end]" value="23:59"', $html, '"24:00" is stored as 23:59');
        $this->assertSame($before, $this->rulesFor((int) $staff->id), 'viewing the prefill saves nothing');

        // After Save they are ordinary availability rules.
        $this->post($this->url('availability.week.update'), [
            'staff_user_id' => $staff->id,
            'open' => [1 => '1'],
            'days' => [1 => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '18:00']]],
        ])->assertRedirect();
        $this->assertSame(['1 08:00-12:00', '1 13:00-18:00'], $this->rulesFor((int) $staff->id));
    }

    public function test_the_action_is_absent_when_the_location_has_no_business_hours(): void
    {
        $this->authenticate($this->owner->user);

        $this->assertNull($this->locationA->fresh()->hours);

        $html = $this->get($this->url('availability.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="use-business-hours"', $html);

        // A prefill request without hours quietly shows the saved week instead.
        $this->get($this->url('availability.index', ['prefill' => 'business']))->assertOk()->assertDontSee('availability-prefill-note');
    }

    // -----------------------------------------------------------------
    // Header (same lane)
    // -----------------------------------------------------------------

    public function test_the_header_has_no_presence_status_and_no_language_picker(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->get($this->url('schedule'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="user-status"', $html);
        $this->assertStringNotContainsString('dropdown-language', $html);
        $this->assertStringNotContainsString('href="' . url('lang/') , $html);
        // Notifications and the account menu remain.
        $this->assertStringContainsString('dropdown-user', $html);
        $this->assertStringContainsString('dropdown-notification', $html);
    }
}
