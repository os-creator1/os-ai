<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Reporting periods on the final Home: finished, still running, not yet started, invalid. Today is frozen at 2026-09-10 (Business-local). */
class HomeV2PeriodStatesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function home(array $query): string
    {
        return $this->get(route('user.home', $query))->assertOk()->getContent();
    }

    private function card(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="' . $key . '".*?</\1>#s', $html, $m), "{$key} card");

        return $m[0];
    }

    private function established(string $name): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, $name, $name . ' Account');
        $this->contactsAdded($business, 3, '2026-09-03');
        $this->contactsAdded($business, 1, '2026-08-27');
        $this->authenticateAs($owner);

        return [$owner, $business, $workspace];
    }

    public function test_a_period_that_has_not_started_shows_no_zeros_no_comparison_and_no_chart(): void
    {
        $this->established('Future Venue');

        $html = $this->home(['range' => 'custom', 'start' => '2026-09-15', 'end' => '2026-09-25']);

        foreach (['bookings', 'contacts'] as $key) {
            $card = $this->card($html, $key);
            $this->assertStringContainsString('data-role="headline-figure">—<', $card, "{$key}: a dash, not a zero");
            $this->assertStringContainsString('This period has not started', $card);
            $this->assertStringNotContainsString('data-role="kpi-badge"', $card, "{$key}: no comparison");
        }

        // Waiting for reply is a current state, not a period figure: it is still the real number now, with no period comparison.
        $waiting = $this->card($html, 'waiting');
        $this->assertStringContainsString('data-role="headline-figure">0<', $waiting);
        $this->assertStringNotContainsString('▲', $waiting);
        $this->assertStringNotContainsString('▼', $waiting);

        $this->assertStringContainsString('this period has not started yet', $html);
        $this->assertStringContainsString('data-role="home-chart-future"', $html);
        $this->assertStringNotContainsString('data-series-url=', $html, 'No series is requested for days that have not happened.');
        $this->assertStringNotContainsString('Your trend will appear after your first week', $html, 'The reason is the future, not a lack of data.');
    }

    public function test_a_custom_period_that_is_still_running_is_not_compared_and_charts_only_elapsed_days(): void
    {
        [, , $workspace] = $this->established('Running Venue');

        $html = $this->home(['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-20']);

        $contacts = $this->card($html, 'contacts');
        $this->assertStringContainsString('data-role="headline-figure">3<', $contacts, 'The real figure so far.');
        $this->assertStringContainsString('In progress', $contacts);
        $this->assertStringNotContainsString('▼', $contacts, 'Future days are not zeros to compare against.');
        $this->assertStringNotContainsString('▲', $contacts);
        $this->assertStringContainsString('still running', $html);

        // The series covers Sep 1 to today (Sep 10), never the days that have not happened.
        $this->assertSame(1, preg_match('#data-series-url="([^"]+)"#', $html, $m));
        $url = html_entity_decode($m[1]);
        $this->assertStringContainsString('start=2026-09-01', $url);
        $this->assertStringContainsString('end=2026-09-10', $url);
        $this->assertStringNotContainsString('2026-09-20', $url);
        $this->assertNotNull($workspace);
    }

    public function test_a_finished_custom_period_still_compares_normally(): void
    {
        $this->established('Finished Venue');

        $html = $this->home(['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-10']);
        $contacts = $this->card($html, 'contacts');

        $this->assertStringContainsString('data-role="headline-figure">3<', $contacts);
        $this->assertStringContainsString('▲ 2', $contacts);
        $this->assertStringNotContainsString('In progress', $contacts);
        $this->assertStringContainsString('data-series-url=', $html);
    }

    public function test_invalid_custom_ranges_fall_back_visibly_and_never_show_made_up_periods(): void
    {
        $this->established('Invalid Venue');

        foreach ([
            ['range' => 'custom', 'start' => '2026-09-10', 'end' => '2026-09-01'],   // reversed
            ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-09-10'],   // overlong
            ['range' => 'custom', 'start' => 'nope', 'end' => '2026-09-10'],         // malformed
        ] as $query) {
            $html = $this->home($query);
            $this->assertStringContainsString("That date range can't be used", html_entity_decode($html, ENT_QUOTES));
            $contacts = $this->card($html, 'contacts');
            $this->assertStringContainsString('data-role="headline-figure">3<', $contacts, 'It shows the default period, truthfully.');
        }
    }
    public function test_the_bookings_card_equals_the_bookings_list_for_a_location_restricted_actor(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Audit Bookings', 'Audit Account');
        $first = \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $typeOne = (int) \Illuminate\Support\Facades\DB::table('booking_types')->where('business_location_id', $first->id)->value('id');
        $second = \App\Models\BusinessLocation::create(['business_id' => $business->id, 'name' => 'Other site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $typeTwo = \Illuminate\Support\Facades\DB::table('booking_types')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'public_booking_uuid' => (string) \Illuminate\Support\Str::uuid(), 'business_location_id' => $second->id,
            'name' => 'Other consult', 'duration_minutes' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = \Illuminate\Support\Facades\DB::table('contacts')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id, 'location_id' => $first->id,
            'group_id' => $this->contactGroup($business)->id, 'phone' => '15559990001', 'status' => 1, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
        ]);
        foreach ([[$first->id, $typeOne, '2026-09-02 10:00:00'], [$first->id, $typeOne, '2026-09-05 10:00:00'], [$second->id, $typeTwo, '2026-09-06 10:00:00']] as [$loc, $type, $at]) {
            \Illuminate\Support\Facades\DB::table('appointments')->insert([
                'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_location_id' => $loc, 'booking_type_id' => $type, 'staff_user_id' => $business->customer_id,
                'contact_id' => $contact, 'status' => 'scheduled', 'start_at' => '2026-12-01 10:00:00', 'end_at' => '2026-12-01 10:30:00', 'reschedule_count' => 0,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => \App\Enums\Workspace\LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign($membership, $first);
        $this->authenticateAs($staff->fresh(), ['view_reports', 'access_backend']);

        $html = $this->home(['range' => 'this_month']);
        $card = $this->card($html, 'bookings');
        $this->assertSame(1, preg_match('#data-role="headline-figure">(\d+)<#', $card, $f));
        $this->assertSame(1, preg_match('#href="([^"]*/calendar/bookings\?[^"]*)"#', $card, $u));

        $rows = substr_count($this->get(html_entity_decode($u[1]))->assertOk()->getContent(), 'data-role="booking-row"');
        $this->assertSame(2, $rows, 'Only the reachable Location.');
        $this->assertSame((string) $rows, $f[1], 'The card equals the records behind it.');
    }
}
