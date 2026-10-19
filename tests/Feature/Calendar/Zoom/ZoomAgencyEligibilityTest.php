<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Library\Calendar\Zoom\ZoomConnectionManager;
use App\Library\Calendar\Zoom\ZoomEligibility;
use App\Models\Business;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agency client eligibility. A Client Workspace's entitlement is its own plan row (an Agency may resell only Core/Growth), so the
 * Zoom rule must let an active Agency cover its actively managed clients without making each client buy Growth, while keeping
 * every Business's Zoom authorization separate and tenants isolated.
 */
class ZoomAgencyEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use ZoomTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootZoom();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\Workspace, 1: Business, 2: \App\Models\User} */
    private function world(string $tier): array
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $row = (array) DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->first();
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->delete();
        unset($row['id']);
        $row['workspace_id'] = $workspace->id;
        $row['workspace_plan_catalog_id'] = WorkspacePlanCatalog::query()->where('tier', $tier)->value('id');
        if (array_key_exists('uid', $row)) {
            $row['uid'] = (string) \Illuminate\Support\Str::uuid();
        }
        DB::table('workspace_plan_assignments')->insert($row);

        return [$workspace, $business->fresh(), $owner->user];
    }

    private function manage(int $agencyWorkspaceId, int $clientWorkspaceId, string $status = 'active'): void
    {
        DB::table('agency_client_workspace_relationships')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'agency_workspace_id' => $agencyWorkspaceId, 'client_workspace_id' => $clientWorkspaceId, 'status' => $status,
            'established_by_user_id' => $this->owner->user_id ?? 1, 'established_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function eligible(Business $b): bool
    {
        return app(ZoomEligibility::class)->allows($b);
    }

    public function test_an_active_agency_covers_its_actively_managed_core_client_without_a_growth_purchase(): void
    {
        [$agency, $agencyBiz] = $this->world('agency');
        [$client, $clientBiz] = $this->world('core');
        $this->manage($agency->id, $client->id);

        $this->assertTrue($this->eligible($agencyBiz), 'the Agency operating Business');
        $this->assertTrue($this->eligible($clientBiz), 'a managed Core client');
    }

    public function test_a_core_workspace_that_nobody_manages_stays_ineligible(): void
    {
        [, $coreBiz] = $this->world('core');
        $this->assertFalse($this->eligible($coreBiz));
    }

    public function test_a_terminated_relationship_or_a_lapsed_agency_removes_client_eligibility(): void
    {
        [$agency] = $this->world('agency');
        [$ended, $endedBiz] = $this->world('core');
        $this->manage($agency->id, $ended->id, 'terminated');
        $this->assertFalse($this->eligible($endedBiz));

        [$client, $clientBiz] = $this->world('core');
        $this->manage($agency->id, $client->id);
        $this->assertTrue($this->eligible($clientBiz));

        DB::table('workspace_plan_assignments')->where('workspace_id', $agency->id)->update(['status' => 'locked']);
        $this->assertFalse($this->eligible($clientBiz->fresh()), 'Agency plan no longer active');
    }

    public function test_a_client_of_a_growth_or_core_manager_is_not_covered(): void
    {
        [$notAnAgency] = $this->world('growth');
        [$client, $clientBiz] = $this->world('core');
        $this->manage($notAnAgency->id, $client->id);

        $this->assertFalse($this->eligible($clientBiz));
    }

    public function test_one_agency_never_grants_eligibility_to_another_agencys_client(): void
    {
        [$agencyA] = $this->world('agency');
        [$agencyB] = $this->world('core');   // B is not an active Agency
        [$clientOfB, $clientBizOfB] = $this->world('core');
        $this->manage($agencyB->id, $clientOfB->id);

        $this->assertFalse($this->eligible($clientBizOfB), 'A being an Agency says nothing about B\'s clients');
    }

    public function test_each_business_has_its_own_authorization_and_nothing_is_borrowed_from_the_agency(): void
    {
        [$agency, $agencyBiz, $agencyUser] = $this->world('agency');
        [$client, $clientBiz, $clientUser] = $this->world('core');
        $this->manage($agency->id, $client->id);

        $agencyConn = $this->connect($agencyUser, $agencyBiz->id, 'agency-token');
        $clientConn = $this->connect($clientUser, $clientBiz->id, 'client-token');

        $manager = app(ZoomConnectionManager::class);
        $this->assertSame($agencyConn->id, $manager->activeFor($agencyBiz->id, $agencyUser->id)?->id);
        $this->assertSame($clientConn->id, $manager->activeFor($clientBiz->id, $clientUser->id)?->id);
        $this->assertNull($manager->activeFor($clientBiz->id, $agencyUser->id), 'the Agency user holds no connection in the client Business');
        $this->assertNull($manager->activeInBusiness($agencyConn->id, $clientBiz->id), 'the Agency connection is not usable inside the client Business');
    }

    public function test_billing_rows_are_untouched_by_the_eligibility_check(): void
    {
        [$agency] = $this->world('agency');
        [$client, $clientBiz] = $this->world('core');
        $this->manage($agency->id, $client->id);
        $before = DB::table('workspace_plan_assignments')->get()->toJson();

        $this->eligible($clientBiz);

        $this->assertSame($before, DB::table('workspace_plan_assignments')->get()->toJson());
    }
}
