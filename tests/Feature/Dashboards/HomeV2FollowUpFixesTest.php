<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Dashboard\DashboardSnapshot;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Regression tests: the Bookings destination, completed work under the Done cap, and established-versus-new businesses. */
class HomeV2FollowUpFixesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function homeHtml(array $query = []): string
    {
        return $this->get(route('user.home', $query))->assertOk()->getContent();
    }

    private function appointment(int $locationId, int $typeId, int $contactId, int $staffId, string $createdAt, string $startAt, string $status = 'scheduled'): void
    {
        DB::table('appointments')->insert([
            'uid' => (string) Str::uuid(), 'business_location_id' => $locationId, 'booking_type_id' => $typeId, 'staff_user_id' => $staffId,
            'contact_id' => $contactId, 'status' => $status, 'start_at' => $startAt, 'end_at' => \Carbon\Carbon::parse($startAt)->addMinutes(30),
            'reschedule_count' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function contactRow($business, int $locationId, string $phone): int
    {
        return DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id, 'location_id' => $locationId,
            'group_id' => $this->contactGroup($business)->id, 'phone' => $phone, 'status' => 1, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
        ]);
    }

    // ── 1 · Bookings opens the records the card counts ──────────────────

    public function test_the_bookings_card_opens_every_booking_created_in_the_period_across_locations_including_future_visits(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Booked Venue', 'Booked Account');
        $first = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $typeOne = (int) DB::table('booking_types')->where('business_location_id', $first)->value('id');
        $second = BusinessLocation::create(['business_id' => $business->id, 'name' => 'North site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $typeTwo = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(), 'business_location_id' => $second->id,
            'name' => 'North consult', 'duration_minutes' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = (int) $business->customer_id;
        $contactOne = $this->contactRow($business, $first, '15551110001');
        $contactTwo = $this->contactRow($business, (int) $second->id, '15551110002');

        // Booked TODAY for a visit two months away; booked 20 days ago at the other Location (visit already past); one cancelled; one booked before the period.
        $this->appointment($first, $typeOne, $contactOne, $staff, '2026-09-10 10:00:00', '2026-11-20 15:00:00');
        $this->appointment((int) $second->id, $typeTwo, $contactTwo, $staff, '2026-08-21 10:00:00', '2026-08-25 15:00:00');
        $this->appointment($first, $typeOne, $contactOne, $staff, '2026-09-09 10:00:00', '2026-09-30 15:00:00', 'cancelled');
        $this->appointment($first, $typeOne, $contactOne, $staff, '2026-06-01 10:00:00', '2026-06-05 15:00:00');
        $this->authenticateAs($owner);

        $html = $this->homeHtml(['range' => 'last_30_days']);
        $this->assertSame(1, preg_match('#<a[^>]*href="([^"]*/calendar/bookings\?[^"]*)"[^>]*data-kpi="bookings"#', $html, $m), 'The card links to the bookings list.');
        $url = html_entity_decode($m[1]);
        $this->assertStringContainsString('created_from=2026-08-12', $url);
        $this->assertStringContainsString('created_to=2026-09-10', $url);

        $list = $this->get($url)->assertOk()->getContent();
        $this->assertSame(2, substr_count($list, 'data-role="booking-row"'), 'The card counts 2: the future visit booked today and the other Location, not the cancelled or earlier ones.');
        $this->assertStringContainsString('North consult', $list);
        $this->assertStringContainsString('Nov 20, 2026', $list);

        // A longer period than the 14-day schedule window works, and a narrower one narrows.
        $today = $this->get(route('customer.workspaces.businesses.calendar.bookings', [$workspace->uid, $business->uid, 'created_from' => '2026-09-10', 'created_to' => '2026-09-10']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($today, 'data-role="booking-row"'));

        // An invalid range is ignored (never an error, never wider than the actor's Locations).
        $bad = $this->get(route('customer.workspaces.businesses.calendar.bookings', [$workspace->uid, $business->uid, 'created_from' => 'x', 'created_to' => '2026-09-10']))->assertOk()->getContent();
        $this->assertSame(3, substr_count($bad, 'data-role="booking-row"'), 'Unfiltered: every non-cancelled booking.');
    }

    public function test_the_bookings_list_shows_only_the_locations_the_actor_may_reach_and_no_other_business(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Scoped Venue', 'Scoped Account');
        $first = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $typeOne = (int) DB::table('booking_types')->where('business_location_id', $first->id)->value('id');
        $second = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Hidden site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $typeTwo = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(), 'business_location_id' => $second->id,
            'name' => 'Hidden consult', 'duration_minutes' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = $this->contactRow($business, (int) $first->id, '15552220001');
        $this->appointment((int) $first->id, $typeOne, $contact, (int) $business->customer_id, '2026-09-09 10:00:00', '2026-09-20 15:00:00');
        $this->appointment((int) $second->id, $typeTwo, $contact, (int) $business->customer_id, '2026-09-09 11:00:00', '2026-09-21 15:00:00');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign($membership, $first);
        $this->authenticateAs($staff->fresh(), ['access_backend', 'view_reports']);

        $url = route('customer.workspaces.businesses.calendar.bookings', [$workspace->uid, $business->uid]);
        $list = $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, substr_count($list, 'data-role="booking-row"'));
        $this->assertStringNotContainsString('Hidden consult', $list);
        $this->assertStringNotContainsString('Hidden site', $list);

        // Another Business's actor reaches neither the list nor its rows.
        [$stranger] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Venue', 'Stranger Account');
        $this->authenticateAs($stranger);
        $this->assertNotSame(200, $this->get($url)->getStatusCode());
    }

    // ── 2 · completed work survives the handling cap ────────────────────

    public function test_done_for_you_keeps_real_completed_work_when_pending_items_fill_the_handling_cap(): void
    {
        config(['home.handling_visible' => 1]);
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Busy Handling', 'Busy Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, \Carbon\CarbonImmutable::now()->subHours(2)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'));
        $this->recommendation($business, ['title' => 'Pending one', 'status' => 'in_progress']);
        $this->recommendation($business, ['title' => 'Pending two', 'status' => 'awaiting_approval']);
        $this->authenticateAs($owner);

        $home = $this->dashboardFor($owner->user)->band(DashboardSnapshot::BAND_COMMAND);

        $this->assertCount(1, $home['handling'], 'The mixed list is still capped for its other consumers.');
        $this->assertNotEmpty($home['completedWork'], 'Completed work is selected before any cap.');
        $this->assertNotEmpty($home['v2']['done']['rows'], 'Done for you shows it.');
        $keys = array_map(fn ($row) => $row['text'] . '|' . $row['at'], $home['v2']['done']['rows']);
        $this->assertSame($keys, array_values(array_unique($keys)), 'No duplicate events.');
        foreach ($home['v2']['done']['rows'] as $row) {
            $this->assertStringNotContainsString('Pending', $row['text'], 'A pending item is never listed as done.');
        }
    }

    // ── 3 · established versus new ──────────────────────────────────────

    public function test_a_business_with_only_old_history_keeps_the_operational_home(): void
    {
        [$owner, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Old Hands', 'Old Account');
        $this->contactsAdded($business, 1, '2025-02-01'); // months before either reporting period
        $this->authenticateAs($owner);

        $this->assertStringContainsString('data-home-layout="home"', $this->homeHtml());
    }

    public function test_an_old_conversation_alone_also_counts_as_history(): void
    {
        [$owner, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Chatty Past', 'Chatty Account');
        $this->conversationWith($business, [['outgoing', '2025-03-01 10:00:00']]);
        $this->authenticateAs($owner);

        $this->assertStringContainsString('data-home-layout="home"', $this->homeHtml());
    }

    public function test_a_business_with_no_history_at_all_is_still_new(): void
    {
        [$owner] = $this->bareTenant(WorkspacePlanTier::Growth, 'Day One', 'Day One Account');
        $this->authenticateAs($owner);

        $this->assertStringContainsString('data-home-layout="setup"', $this->homeHtml());
    }

    public function test_unavailable_history_is_unknown_and_never_treated_as_new(): void
    {
        [$owner] = $this->bareTenant(WorkspacePlanTier::Growth, 'Unknown Past', 'Unknown Account');
        $this->authenticateAs($owner);
        $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('hasAnyContact')->andThrow(new RuntimeException('contacts unavailable'));
        });

        $this->assertStringContainsString('data-home-layout="home"', $this->homeHtml(), 'Not proven new, so not the new-business Home.');
    }
}
