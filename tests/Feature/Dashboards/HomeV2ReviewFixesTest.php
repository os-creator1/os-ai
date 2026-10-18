<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Regression tests for the review findings on the final Home: inbox filters, calendar window, honest history and setup. */
class HomeV2ReviewFixesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function load(array $uids, array $params): string
    {
        return $this->post(route('customer.workspaces.businesses.conversations.load', $uids), $params)->assertOk()->getContent();
    }

    private function box(\App\Models\Business $business, string $to, string $direction, string $at, bool $pinned = false): int
    {
        $id = $this->conversationWith($business, [[$direction, $at]]);
        DB::table('chat_boxes')->where('id', $id)->update(['to' => $to, 'pinned' => $pinned]);

        return $id;
    }

    private function ago(int $minutes): string
    {
        return \Carbon\CarbonImmutable::now()->subMinutes($minutes)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function kpiHtml(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="' . $key . '".*?</\1>#s', $html, $m));

        return $m[0];
    }

    // 1 · every waiting conversation is reachable, however many there are
    public function test_the_waiting_filter_reaches_every_match_beyond_two_hundred(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Busy Inbox', 'Busy Account');
        $this->authenticateAs($customer);
        $uids = [$workspace->uid, $business->uid];

        // 230 waiting conversations (customer wrote last, well past the grace period), the OLDEST touched first.
        $rows = [];

        for ($i = 0; $i < 230; $i++) {
            $at = $this->ago(600 - $i);
            $rows[] = ['uid' => (string) Str::uuid(), 'user_id' => $business->customer_id, 'business_id' => $business->id, 'from' => '18005550100', 'to' => '1707' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'notification' => 0, 'created_at' => $at, 'updated_at' => $at];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('chat_boxes')->insert($chunk);
        }

        $ids = DB::table('chat_boxes')->where('business_id', $business->id)->orderBy('id')->pluck('id');
        $messages = [];

        foreach ($ids as $id) {
            $messages[] = ['box_id' => $id, 'message' => 'Hi', 'sms_type' => 'sms', 'send_by' => 'to', 'direction' => 'incoming', 'created_at' => $this->ago(300), 'updated_at' => $this->ago(300)];
        }

        foreach (array_chunk($messages, 100) as $chunk) {
            DB::table('chat_box_messages')->insert($chunk);
        }

        // The last page of the 230 (50 per page) still lists the OLDEST waiting conversation: nothing is cut at 200.
        $last = $this->load($uids, ['filter' => 'waiting', 'page' => 5]);
        $this->assertStringContainsString('17070000000', $last);

        $this->assertStringNotContainsString('17070000000', $this->load($uids, ['filter' => 'waiting', 'page' => 1]));
    }

    // 2 · pinned conversations obey the same filters
    public function test_pinned_conversations_respect_the_waiting_and_date_filters(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Pinned Inbox', 'Pinned Account');
        $this->authenticateAs($customer);
        $index = route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]);

        $this->box($business, '17081110001', 'incoming', $this->ago(120), true);   // pinned, waiting
        $this->box($business, '17081110002', 'outgoing', $this->ago(120), true);   // pinned, answered

        $all = $this->get($index)->assertOk()->getContent();
        $this->assertStringContainsString('17081110001', $all);
        $this->assertStringContainsString('17081110002', $all);

        $waiting = $this->get($index . '?filter=waiting')->assertOk()->getContent();
        $this->assertStringContainsString('17081110001', $waiting);
        $this->assertStringNotContainsString('17081110002', $waiting, 'A pinned thread that is not waiting is not shown in the waiting view.');

        // Created on Sep 10 (frozen today): inside Sep 1–10, outside Aug 1–10.
        $inside = $this->get($index . '?created_from=2026-09-01&created_to=2026-09-10')->assertOk()->getContent();
        $this->assertStringContainsString('17081110001', $inside);
        $outside = $this->get($index . '?created_from=2026-08-01&created_to=2026-08-10')->assertOk()->getContent();
        $this->assertStringNotContainsString('17081110001', $outside);
        $this->assertStringNotContainsString('17081110002', $outside);
    }

    // 3 · the calendar window survives the single-location redirect and the multi-location picker
    public function test_the_calendar_keeps_the_requested_window_through_redirect_and_picker(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Calendar Venue', 'Calendar Account');
        $this->authenticateAs($customer);
        $query = ['view' => 'week', 'days' => '14', 'date' => '2026-09-01'];
        $index = route('customer.workspaces.businesses.calendar.index', array_merge([$workspace->uid, $business->uid], $query));

        $redirect = $this->get($index);
        $redirect->assertRedirect();
        $target = (string) $redirect->headers->get('Location');
        $this->assertStringContainsString('days=14', $target);
        $this->assertStringContainsString('date=2026-09-01', $target);
        $this->get($target)->assertOk();

        BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US', 'name' => 'Second site']);
        $picker = $this->get($index)->assertOk()->getContent();
        $links = substr_count($picker, 'data-role="calendar-location"');
        $this->assertGreaterThanOrEqual(2, $links);
        $this->assertSame($links, preg_match_all('#data-role="calendar-location"\s+href="[^"]*days=14[^"]*date=2026-09-01#', $picker), 'Every location link carries the window.');
    }

    // 4 · bookings history stays visible when every booking type is switched off
    public function test_bookings_stay_a_real_figure_after_all_booking_types_are_deactivated(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'History Venue', 'History Account');
        $this->contactsAdded($business, 2, '2026-09-02'); // a Business with recorded activity is not on the new-business Home
        $this->authenticateAs($customer);
        DB::table('booking_types')->update(['is_active' => false, 'created_at' => '2026-01-01 00:00:00']);

        $card = $this->kpiHtml($this->get(route('user.home'))->assertOk()->getContent(), 'bookings');

        $this->assertStringNotContainsString('—', $card, 'Existing history is a figure, not a dash.');
        $this->assertStringContainsString('data-role="headline-figure">0<', $card);
    }

    // 5 · a period before the Business existed is unobserved, not zero
    public function test_a_period_before_the_business_existed_is_a_dash_not_a_zero(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Born September', 'Born Account');
        DB::table('businesses')->where('id', $business->id)->update(['created_at' => '2026-09-05 12:00:00']);
        DB::table('booking_types')->update(['created_at' => '2026-09-05 12:00:00']);
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home', ['range' => 'last_month']))->assertOk()->getContent();

        foreach (['contacts', 'bookings'] as $key) {
            $card = $this->kpiHtml($html, $key);
            $this->assertStringContainsString('data-role="headline-figure">—<', $card, "{$key}: August was before the Business existed.");
            $this->assertStringNotContainsString('data-tone=', $card, "{$key}: no comparison for an unobserved period.");
        }

        $thisMonth = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="headline-figure">0<', $this->kpiHtml($thisMonth, 'contacts'), 'The month it was created in is observed: a real zero.');
    }

    // 6 · an archived catalog item is not a completed package setup
    public function test_an_archived_catalog_item_does_not_complete_the_packages_step(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Catalog Venue', 'Catalog Account');
        $this->authenticateAs($customer);
        $item = DB::table('catalog_items')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'type' => 'product', 'name' => 'Starter package',
            'price_minor' => 1000, 'currency_code' => 'USD', 'position' => 1, 'lifecycle_state' => 'archived', 'archived_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $done = fn () => preg_match('#data-step="packages"\s+data-done="(\w+)"#', $this->get(route('user.home'))->assertOk()->getContent(), $m) ? $m[1] : null;

        $this->assertSame('false', $done(), 'Archived is not setup.');

        DB::table('catalog_items')->where('id', $item)->update(['lifecycle_state' => 'active', 'archived_at' => null]);
        $this->assertSame('true', $done());
    }
}
