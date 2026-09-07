<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §15 / §32.2 — the mandatory tenancy chain.
 *
 * EVERY TENANCY failure is 404, never 403 (contract §15.2): a foreign
 * resource must be indistinguishable from a nonexistent one.
 *
 * A PERMISSION failure is a different concern and produces this
 * platform's own established refusal code: App\Exceptions\Handler
 * (lines 81-82) renders AuthorizationException as the errors.401 view
 * with HTTP 401 for every customer-side request. That is pre-existing,
 * repository-wide behaviour that GBP inherits rather than overrides, so
 * these tests assert 401 — the contract's requirement is that the request
 * is REFUSED server-side, which it is.
 */
class GoogleBusinessProfileTenancyTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /** T-TEN-2 — a valid Workspace uid with a Business from another Workspace. */
    public function test_two_uid_addressing_rejects_a_business_from_another_workspace(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    /** T-TEN-3 / T-TEN-4 — cross-Workspace and cross-Business are denied. */
    public function test_cross_tenant_access_is_denied(): void
    {
        [$customer] = $this->entitledTenant();
        [, $otherBusiness, $otherWorkspace] = $this->entitledTenant();

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$otherWorkspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    /** T-TEN-5 / T-TEN-6 — a foreign connection and a foreign binding are unreachable. */
    public function test_a_foreign_binding_uid_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $this->createLocation($business);
        $otherLocation = $this->createLocation($otherBusiness);
        $otherConnection = $this->activeConnection($otherBusiness);

        $foreignBinding = \App\Models\BusinessGoogleLocation::create([
            'business_google_connection_id' => $otherConnection->id,
            'business_id' => $otherBusiness->id,
            'business_location_id' => $otherLocation->id,
            'provider_account_resource_name' => 'accounts/AX',
            'provider_location_resource_name' => 'locations/LX',
        ]);

        $this->activeConnection($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.unbind', [$workspace->uid, $business->uid]), [
            'binding_uid' => $foreignBinding->uid,
        ])->assertNotFound();

        // The foreign binding is untouched.
        $this->assertDatabaseHas('business_google_locations', ['id' => $foreignBinding->id]);
    }

    /** T-TEN-7 — an inactive Workspace blocks every route. */
    public function test_inactive_workspace_blocks_every_route(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);

        foreach (['index', 'comparison', 'settings', 'connect', 'locations'] as $name) {
            $this->get(route('customer.workspaces.businesses.gbp.' . $name, [$workspace->uid, $business->uid]))
                ->assertNotFound();
        }

        // Even disconnect/unbind — which skip the ENTITLEMENT step by
        // design (contract §39.4) — still require an active Workspace.
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    /** An inactive Business is also refused. */
    public function test_inactive_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Draft->value]);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    /** T-TEN-8 — an inactive membership is refused. */
    public function test_inactive_membership_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $member = $this->createCustomer()->user;

        $this->addMember($workspace, $member, WorkspaceMembershipRole::Admin, false);
        $this->authenticateAsUser($member);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    /** T-TEN-9 — a Selected-scope member WITHOUT an assignment is refused. */
    public function test_selected_scope_member_without_assignment_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $member = $this->createCustomer()->user;

        $this->addMember($workspace, $member, WorkspaceMembershipRole::Staff, true, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAsUser($member);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    /** An all-scope active member reaches the surface. */
    public function test_all_scope_active_member_can_view(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $member = $this->createCustomer()->user;

        $this->addMember($workspace, $member, WorkspaceMembershipRole::Admin, true, WorkspaceBusinessAccessScope::All);
        $this->authenticateAsUser($member);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    /**
     * T-TEN-10 — view permission gates the view surfaces.
     */
    public function test_view_permission_is_required_for_view_surfaces(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, ['manage_google_business_profile']);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertStatus(401);
    }

    /**
     * T-TEN-11 — manage permission gates every mutating and provider-
     * touching action. A view-only user may read, but may not connect,
     * enumerate, bind, unbind, disconnect or refresh.
     */
    public function test_manage_permission_is_required_for_every_managing_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);

        $this->authenticateAsCustomer($customer, ['view_google_business_profile']);
        $args = [$workspace->uid, $business->uid];

        $this->get(route('customer.workspaces.businesses.gbp.index', $args))->assertOk();

        $this->get(route('customer.workspaces.businesses.gbp.connect', $args))->assertStatus(401);
        $this->get(route('customer.workspaces.businesses.gbp.callback', $args))->assertStatus(401);
        $this->get(route('customer.workspaces.businesses.gbp.locations', $args))->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.gbp.refresh', $args))->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.gbp.unbind', $args), ['binding_uid' => 'x'])->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.gbp.bind', $args), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => 'x',
        ])->assertStatus(401);

        // No provider call was made by any of the refused requests.
        $this->assertSame([], $this->fakeGoogle->calls);
    }

    /**
     * T-TEN-12 — an unknown Workspace or Business uid is 404, never 403,
     * so a foreign resource is indistinguishable from a nonexistent one.
     */
    public function test_unknown_uids_return_404_not_403(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', ['no-such-workspace', $business->uid]))
            ->assertNotFound();

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, 'no-such-business']))
            ->assertNotFound();
    }

    /**
     * Contract §39.4 — credentials must never be trapped. A downgraded
     * (Core) Business can still DISCONNECT, even though every other route
     * 404s, and doing so makes no provider call.
     */
    public function test_a_downgraded_business_can_still_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->coreTenant();
        $connection = $this->activeConnection($business);

        $this->authenticateAsCustomer($customer);
        $args = [$workspace->uid, $business->uid];

        $this->get(route('customer.workspaces.businesses.gbp.index', $args))->assertNotFound();

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', $args));

        $connection->refresh();

        $this->assertSame('disconnected', $connection->state->value);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertSame([], $this->fakeGoogle->calls);
    }
}
