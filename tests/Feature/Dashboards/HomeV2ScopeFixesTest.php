<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Contacts\ContactDirectory;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Regression tests: Home's contacts/conversations figures follow the actor's Locations, and the inbox "waiting" view stays coherent. */
class HomeV2ScopeFixesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function figure(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="' . $key . '".*?</\1>#s', $html, $m));
        $this->assertSame(1, preg_match('#data-role="headline-figure">([^<]+)<#', $m[0], $f));

        return $f[1];
    }

    private function contactAt($business, ?int $locationId, string $createdAt): void
    {
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id, 'location_id' => $locationId,
            'group_id' => $this->contactGroup($business)->id, 'phone' => '1555' . random_int(1000000, 9999999), 'status' => 'subscribe',
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    private function boxAt($business, ?int $locationId, string $createdAt, string $to, bool $pinned = false): int
    {
        $id = $this->conversationWith($business, [['incoming', $createdAt]]);
        DB::table('chat_boxes')->where('id', $id)->update(['location_id' => $locationId, 'to' => $to, 'pinned' => $pinned, 'created_at' => $createdAt]);

        return $id;
    }

    // ── 1 · the figures equal what the actor can open ───────────────────

    public function test_new_contacts_and_conversations_count_only_the_locations_the_actor_may_reach(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Two Site', 'Two Site Account');
        $first = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $second = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Hidden site', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $thisPeriod = '2026-09-03 12:00:00';
        $earlier = '2026-08-30 12:00:00'; // inside the 10 days before Sep 1–10

        // Contacts: this period 2 at the first site, 3 at the hidden site, 1 Business-wide (no site). Before: 1 visible, 4 hidden.
        foreach ([[$first->id, 2], [$second->id, 3], [null, 1]] as [$loc, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $this->contactAt($business, $loc, $thisPeriod);
            }
        }
        $this->contactAt($business, (int) $first->id, $earlier);
        for ($i = 0; $i < 4; $i++) {
            $this->contactAt($business, (int) $second->id, $earlier);
        }

        // Conversations: this period 1 at the first site, 2 hidden, 1 Business-wide.
        $this->boxAt($business, (int) $first->id, $thisPeriod, '17090000001');
        $this->boxAt($business, (int) $second->id, $thisPeriod, '17090000002');
        $this->boxAt($business, (int) $second->id, $thisPeriod, '17090000003');
        $this->boxAt($business, null, $thisPeriod, '17090000004');

        $this->authenticateAs($owner);
        $ownerHtml = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertSame('6', $this->figure($ownerHtml, 'contacts'));
        $this->assertSame('4', $this->figure($ownerHtml, 'conversations'));

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign($membership, $first);
        $this->authenticateAs($staff->fresh(), ['view_reports', 'access_backend', 'view_contact', 'chat_box']);

        $html = $this->get(route('user.home'))->assertOk()->getContent();
        $this->assertSame('3', $this->figure($html, 'contacts'), '2 at the reachable site plus the Business-wide one: not the 3 at the hidden site.');
        $this->assertSame('2', $this->figure($html, 'conversations'));
        // The previous period is scoped too: 1 visible contact before, so 3 is an increase of 2 (not a drop from 5).
        $this->assertStringContainsString('▲ 2', $this->kpiCard($html, 'contacts'));

        // The figure equals the list the card opens.
        $range = \App\Library\Dashboard\Home\HomeDestinationRange::fromRequest(
            \Illuminate\Http\Request::create('/', 'GET', ['created_from' => '2026-09-01', 'created_to' => '2026-09-10']),
            $business,
        );
        $listed = app(ContactDirectory::class)->page($business, '', (int) $staff->user_id, null, $range)->total();
        $this->assertSame(3, $listed);
    }

    private function kpiCard(string $html, string $key): string
    {
        preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="' . $key . '".*?</\1>#s', $html, $m);

        return $m[0] ?? '';
    }

    // ── 2 · the Waiting view is coherent: banner, pinned rows, tabs ─────

    public function test_the_waiting_banner_and_pinned_rows_follow_the_url_not_a_stale_tab(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Inbox Venue', 'Inbox Account');
        $old = \Carbon\CarbonImmutable::now()->subHours(3)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        $this->boxAt($business, null, $old, '17091110001', true);   // pinned, waiting
        $answered = $this->conversationWith($business, [['outgoing', $old]]);
        DB::table('chat_boxes')->where('id', $answered)->update(['to' => '17091110002', 'pinned' => true]);   // pinned, answered
        $this->authenticateAs($owner);
        $index = route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]);

        $waiting = $this->get($index . '?filter=waiting&created_from=2026-09-01&created_to=2026-09-10')->assertOk()->getContent();
        $this->assertStringContainsString('customers waiting for a reply', $waiting);
        $this->assertStringContainsString('17091110001', $waiting);
        $this->assertStringNotContainsString('17091110002', $waiting, 'Waiting pins only the pinned threads that are waiting.');

        // After a tab change the URL names that tab: no waiting banner, and the pinned rows are no longer waiting-filtered; the explicit date range stays.
        $unread = $this->get($index . '?filter=unread&created_from=2026-09-01&created_to=2026-09-10')->assertOk()->getContent();
        $this->assertStringNotContainsString('customers waiting for a reply', $unread);
        $this->assertStringContainsString('conversations started Sep 1 – Sep 10', $unread);
        $this->assertStringContainsString('17091110001', $unread);
        $this->assertStringContainsString('17091110002', $unread);

        // The page reloads onto the chosen tab when leaving "waiting", and marks the active tab from the URL.
        $this->assertStringContainsString('window.location.assign(next.toString())', $waiting);
        $this->assertStringContainsString('knownTabs', $waiting);
    }
}
