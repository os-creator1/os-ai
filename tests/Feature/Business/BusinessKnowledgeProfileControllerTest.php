<?php

namespace Tests\Feature\Business;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\BusinessKnowledgeProfileChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §3.3 (Option A, locked), §16 Slice
 * 2 -- the Business Knowledge Profile customer-portal surface. Mirrors
 * WebsiteTenancyTest's shape exactly: owner/admin/staff access,
 * inactive-member denial, missing-permission denial, foreign
 * Workspace/Business 404s, and a cross-tenant IDOR proof.
 */
class BusinessKnowledgeProfileControllerTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Authorization: owner / admin / staff / inactive / permission
    // ---------------------------------------------------------------

    public function test_owner_can_access_the_completeness_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Business Details');
    }

    public function test_active_admin_member_can_access_the_completeness_page(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $admin = $this->createCustomer()->user;
        $this->addMember($workspace, $admin, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_active_staff_member_can_access_the_completeness_page(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $staff = $this->createCustomer()->user;
        $this->addMember($workspace, $staff, WorkspaceMembershipRole::Staff);
        $this->authenticateAsUser($staff);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_inactive_member_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();

        $former = $this->createCustomer()->user;
        $this->addMember($workspace, $former, WorkspaceMembershipRole::Admin, false);
        $this->authenticateAsUser($former);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_missing_website_permission_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Foreign identifiers / cross-tenant protection
    // ---------------------------------------------------------------

    public function test_foreign_workspace_uid_is_not_found(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$otherWorkspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_foreign_business_uid_inside_own_workspace_is_not_found(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    public function test_a_foreign_tenant_cannot_write_another_businesses_profile(): void
    {
        [$customerA, , $workspaceA] = $this->entitledTenant();
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspaceB->uid, $businessB->uid]), [
            'ideal_customers' => 'Attacker-supplied value',
        ])->assertNotFound();

        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $businessB->id)->count());
    }

    public function test_a_foreign_tenant_cannot_write_another_businesses_location_hours(): void
    {
        [$customerA] = $this->entitledTenant();
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();
        $locationB = $this->addLocation($businessB, ['is_primary' => true]);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspaceB->uid, $businessB->uid, $locationB->uid]), [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ])->assertNotFound();

        $this->assertNull($locationB->fresh()->hours);
    }

    // ---------------------------------------------------------------
    // Read-only completeness display
    // ---------------------------------------------------------------

    public function test_missing_stale_and_present_fields_are_all_displayed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, ['brand_voice' => 'Friendly'], 'manual_edit', $customer->user_id, markVerified: true);
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee("How would you describe your brand's tone?", false);
        $response->assertSee('Who are your ideal customers?', false);
    }

    public function test_viewing_the_completeness_page_creates_no_profile_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))->assertOk();
        $this->get(route('customer.workspaces.businesses.knowledge-profile.show', [$workspace->uid, $business->uid]))->assertOk();

        $this->assertSame(0, \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // Writes: atomicity, no-op, hours
    // ---------------------------------------------------------------

    public function test_updating_fields_persists_through_the_manager_seam(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners in the local area',
            'years_operating' => '10',
        ])->assertRedirect();

        $profile = \App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $this->assertSame('Homeowners in the local area', $profile->ideal_customers);
        $this->assertSame(10, $profile->years_operating);
    }

    public function test_an_invalid_field_rejects_the_whole_submission_and_saves_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), [
            'ideal_customers' => 'Homeowners',
            'years_operating' => '-5',
        ])->assertSessionHasErrors();

        $this->assertNull(\App\Models\BusinessKnowledgeProfile::where('business_id', $business->id)->first()?->ideal_customers);
    }

    public function test_resubmitting_identical_answers_creates_no_new_change_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $payload = ['ideal_customers' => 'Homeowners'];

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), $payload)->assertRedirect();
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());

        $this->put(route('customer.workspaces.businesses.knowledge-profile.update', [$workspace->uid, $business->uid]), $payload)->assertRedirect();
        $this->assertSame(1, BusinessKnowledgeProfileChange::where('business_id', $business->id)->count());
    }

    public function test_updating_hours_for_one_location_never_touches_another(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $primary = $this->addLocation($business, ['is_primary' => true]);
        $secondary = $this->addLocation($business, ['is_primary' => false, 'name' => 'Second Shop']);
        $this->authenticateAsCustomer($customer);

        $this->put(route('customer.workspaces.businesses.knowledge-profile.locations.hours', [$workspace->uid, $business->uid, $primary->uid]), [
            'monday' => [['open' => '09:00', 'close' => '17:00']],
        ])->assertRedirect();

        $this->assertNotNull($primary->fresh()->hours);
        $this->assertNull($secondary->fresh()->hours);
    }
}
