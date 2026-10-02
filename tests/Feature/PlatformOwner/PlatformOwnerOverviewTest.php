<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1 §§2, 12, 13 — the overview counts persisted state
 * with bounded queries, neither the overview nor the Workspace/Business
 * cockpit grows its query count as the data behind it grows, and the menu is
 * exactly the four entries, admin-only.
 */
class PlatformOwnerOverviewTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function addLocations(Business $business, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            (new BusinessLocation())->forceFill([
                'uid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'name' => 'Extra Location ' . $i,
                'service_mode' => 'storefront',
                'country_code' => 'US',
                'city' => 'Springfield',
                'is_primary' => false,
            ])->save();
        }
    }

    private function testidValue(string $html, string $testid): int
    {
        $this->assertSame(1, preg_match('/data-testid="' . preg_quote($testid, '/') . '"[^>]*>\s*(\d+)\s*</', $html, $match), "Missing {$testid}");

        return (int) $match[1];
    }

    public function test_overview_counts_canonical_persisted_state(): void
    {
        [, $draftBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Draft Co', 'Draft WS');
        $draftBusiness->forceFill(['status' => BusinessStatus::Draft])->save();
        [, , $suspended] = $this->tenant(WorkspacePlanTier::Growth, 'Suspended Co', 'Suspended WS');
        [, , $locked] = $this->tenant(WorkspacePlanTier::Core, 'Locked Co', 'Locked WS');
        [, $withLocations] = $this->tenant(WorkspacePlanTier::Core, 'Healthy Co', 'Healthy WS');
        $subscribed = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $canceled = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $canceled['subscription']->forceFill(['status' => PlatformSubscriptionStatus::Canceled])->save();

        app(EntitlementManager::class)->changePlanStatus($suspended, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture.');
        app(EntitlementManager::class)->lockForNonPayment($locked, $this->platformAdminId(), 'Fixture.');
        $this->addLocations($withLocations, 3);

        $this->actingAsPlatformOwner();
        $response = $this->get(route('admin.platform-owner.overview'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(Workspace::query()->count(), $this->testidValue($html, 'po-count-workspaces'));
        $this->assertSame(Business::query()->count(), $this->testidValue($html, 'po-count-businesses'));
        $this->assertSame(BusinessLocation::query()->count(), $this->testidValue($html, 'po-count-locations'));

        $this->assertSame(1, $this->testidValue($html, 'po-assignment-suspended'));
        $this->assertSame(1, $this->testidValue($html, 'po-lifecycle-locked'));
        // Granting = the enum's own answer: the Growth one is, the canceled Core one is not.
        $this->assertSame(1, $this->testidValue($html, 'po-subscriptions-granting'));

        // Blocked list: the suspended and the locked Workspace, not a healthy one.
        $response->assertSee('Suspended WS')->assertSee('Locked WS');
        $this->assertSame(2, substr_count($html, 'data-testid="po-blocked-row"'));
        $this->assertStringNotContainsString('Healthy WS', substr($html, strpos($html, 'Recently blocked Workspaces')));
    }

    public function test_overview_with_nothing_blocked_says_so(): void
    {
        $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $this->get(route('admin.platform-owner.overview'))->assertOk()->assertSee('data-testid="po-blocked-none"', false);
    }

    public function test_the_recently_blocked_list_is_capped(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, "Cap Co {$i}", "Cap WS {$i}");
            app(EntitlementManager::class)->lockForNonPayment($workspace, $this->platformAdminId(), 'Fixture.');
        }
        $this->actingAsPlatformOwner();

        $html = $this->get(route('admin.platform-owner.overview'))->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'data-testid="po-blocked-row"'));
    }

    public function test_the_overview_query_count_does_not_grow_with_the_number_of_workspaces(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, "Q Co {$i}", "Q WS {$i}");
            app(EntitlementManager::class)->lockForNonPayment($workspace, $this->platformAdminId(), 'Fixture.');
        }
        $this->actingAsPlatformOwner();

        DB::enableQueryLog();
        $this->get(route('admin.platform-owner.overview'))->assertOk();
        $few = count(DB::getQueryLog());

        for ($i = 4; $i <= 20; $i++) {
            [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, "Q Co {$i}", "Q WS {$i}");
            app(EntitlementManager::class)->lockForNonPayment($workspace, $this->platformAdminId(), 'Fixture.');
        }

        DB::flushQueryLog();
        $this->get(route('admin.platform-owner.overview'))->assertOk();
        $many = count(DB::getQueryLog());

        // The page is capped at 10 blocked rows: 3 -> 3 rows, 20 -> 10 rows.
        // The number of queries must not depend on either.
        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(30, $many);
    }

    public function test_the_workspace_cockpit_query_count_does_not_grow_with_locations_and_members(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growing Co', 'Growing WS');
        // A small but non-empty baseline: eager loads only run once there is
        // at least one parent row, and that one-off step is not "growth".
        $this->addLocations($business, 2);
        for ($i = 0; $i < 2; $i++) {
            $this->member($workspace, $this->createCustomer()->user, WorkspaceMembershipRole::Staff);
        }
        $this->actingAsPlatformOwner();

        DB::enableQueryLog();
        $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $small = count(DB::getQueryLog());

        $this->addLocations($business, 30);
        for ($i = 0; $i < 30; $i++) {
            $this->member($workspace, $this->createCustomer()->user, WorkspaceMembershipRole::Staff);
        }

        DB::flushQueryLog();
        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $large = count(DB::getQueryLog());

        $this->assertSame($small, $large, 'The cockpit must run the same queries for 32 Locations and 32 members as for 2.');
        $this->assertLessThanOrEqual(60, $large);
        $response->assertSee('Showing the first 25 members.');
    }

    public function test_the_business_detail_query_count_does_not_grow_with_locations_and_lists_a_capped_set(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Locations Co', 'Locations WS');
        $this->actingAsPlatformOwner();

        DB::enableQueryLog();
        $this->get(route('admin.businesses.show', $business))->assertOk();
        $small = count(DB::getQueryLog());

        $this->addLocations($business, 40);

        DB::flushQueryLog();
        $html = $this->get(route('admin.businesses.show', $business))->assertOk()->getContent();
        $large = count(DB::getQueryLog());

        $this->assertSame($small, $large);
        $this->assertSame(25, substr_count($html, 'data-testid="po-location-row"'));
    }

    public function test_only_the_four_platform_owner_menu_entries_exist_and_all_are_admin_only(): void
    {
        $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $group = collect(\App\Helpers\Helper::menuData()['admin'])->firstWhere('name', 'Platform Owner');

        $this->assertNotNull($group);
        $this->assertTrue($group['admin_only']);
        $this->assertSame(['Overview', 'Workspaces', 'Businesses', 'Audit'], collect($group['submenu'])->pluck('name')->all());
        $this->assertTrue(collect($group['submenu'])->every(fn ($item) => ($item['admin_only'] ?? false) === true));

        $this->get(route('admin.platform-owner.overview'))->assertOk()->assertSee('Platform Owner');
    }
}
