<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A — contract §37.1 (Tenancy).
 *
 * Every scenario below exercises the AUTHENTICATED customer-portal
 * management controller (Business\WebsiteController), routed under
 * customer.workspaces.businesses.website.*. It mirrors
 * AutomationsTenancyTest's shape (§B4) so the two feature suites stay
 * directly comparable: an inactive membership/Business/Workspace, a
 * foreign Workspace/Business/Website/page/revision/asset uid, and a
 * missing customer-portal permission are all proven to fail closed —
 * as 404 for every tenancy/entitlement failure, and 401 only for the
 * missing 'website' session permission itself.
 */
class WebsiteTenancyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function routeParams(Workspace $workspace, $business, ?string $extraUid = null): array
    {
        $params = [$workspace->uid, $business->uid];

        if ($extraUid !== null) {
            $params[] = $extraUid;
        }

        return $params;
    }

    // ---------------------------------------------------------------
    // Authorization: owner / admin / staff
    // ---------------------------------------------------------------

    public function test_owner_can_access_website_show(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($website->name);
    }

    public function test_active_admin_member_can_access_website_show(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $admin = $this->createCustomer()->user;
        $this->addMember($workspace, $admin, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($website->name);
    }

    public function test_staff_member_with_all_scope_can_access_website_show(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);

        $staff = $this->createCustomer()->user;
        $this->addMember($workspace, $staff, WorkspaceMembershipRole::Staff);
        $this->authenticateAsUser($staff);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertOk()
            ->assertSee($website->name);
    }

    public function test_inactive_member_is_denied(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);

        $former = $this->createCustomer()->user;
        $this->addMember($workspace, $former, WorkspaceMembershipRole::Admin, false);
        $this->authenticateAsUser($former);

        // WorkspaceManager::userCanAccessBusiness() returns false for an
        // inactive membership, so resolveEntitledBusiness() abort(404)s —
        // this is a tenancy failure, not a Gate failure, so it is 404, not
        // 401 (mirroring AutomationsTenancyTest::test_inactive_member_is_denied).
        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_missing_website_permission_is_denied(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer, []);

        // $this->authorize('website') runs FIRST, before any tenancy
        // resolution — a Gate denial throws AuthorizationException, which
        // this app's exception handler maps to 401 (the existing
        // customer-portal convention), exactly like
        // AutomationsTenancyTest::test_missing_automations_permission_is_denied.
        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Foreign identifiers fail closed
    // ---------------------------------------------------------------

    public function test_foreign_workspace_uid_is_not_found(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', [$otherWorkspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_foreign_business_uid_inside_own_workspace_is_not_found(): void
    {
        [$customer, , $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', [$workspace->uid, $otherBusiness->uid]))
            ->assertNotFound();
    }

    public function test_inactive_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_inactive_workspace_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Cross-tenant Website IDOR — a foreign Website (and everything
    // under it) never leaks through ANY management route.
    // ---------------------------------------------------------------

    public function test_foreign_website_and_its_pages_revisions_and_assets_never_leak(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $this->createWebsite($businessA);
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $pageB = $this->homePage($websiteB);

        // Tenant A's session, but every route param below names tenant
        // B's Workspace/Business — the mandatory chain (workspace ->
        // business -> userCanAccessBusiness()) must fail closed as 404
        // for every one of these routes, on tenant A's own credentials.
        $paramsB = [$workspaceB->uid, $businessB->uid];
        $pageParamsB = [$workspaceB->uid, $businessB->uid, $pageB->uid];

        $this->get(route('customer.workspaces.businesses.website.pages.edit', $pageParamsB))->assertNotFound();
        $this->delete(route('customer.workspaces.businesses.website.pages.destroy', $pageParamsB))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.preview', $paramsB))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.history', $paramsB))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.website.publish', $paramsB))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.website.generate', $paramsB))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.website.assets.store', $paramsB), [
            'image' => $this->fakeImageUpload(),
        ])->assertNotFound();
        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', [$workspaceB->uid, $businessB->uid, 'whatever-uid']))->assertNotFound();

        // The victim row is completely unmodified.
        $this->assertSame('Home', $pageB->fresh()->title);
        $this->assertDatabaseHas('website_pages', ['id' => $pageB->id, 'website_id' => $websiteB->id]);
        $this->assertDatabaseHas('websites', ['id' => $websiteB->id, 'business_id' => $businessB->id]);
    }

    public function test_foreign_page_uid_inside_own_website_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        [, $otherBusiness] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($otherBusiness);
        $foreignPage = $this->homePage($otherWebsite);

        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid, $foreignPage->uid];

        $this->get(route('customer.workspaces.businesses.website.pages.edit', $params))->assertNotFound();
        $this->put(route('customer.workspaces.businesses.website.pages.update', $params), ['title' => 'pwned'])->assertNotFound();
        $this->delete(route('customer.workspaces.businesses.website.pages.destroy', $params))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.preview', $params))->assertNotFound();

        $this->assertSame('Home', $foreignPage->fresh()->title);
    }

    public function test_foreign_revision_uid_on_rollback_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid, 'nonexistent-revision-uid'];

        $this->post(route('customer.workspaces.businesses.website.history.rollback', $params))
            ->assertNotFound();
    }

    public function test_foreign_asset_uid_on_destroy_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->authenticateAsCustomer($customer);

        $params = [$workspace->uid, $business->uid, 'nonexistent-asset-uid'];

        $this->delete(route('customer.workspaces.businesses.website.assets.destroy', $params))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Entitlement denial — every reachable decide() denial reason for
    // a fresh Core-tier Business must deny customer.*.website.show.
    // ---------------------------------------------------------------

    public function test_unassigned_workspace_plan_denies_website_show(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);
        // Deliberately no assignFirstPlan() call — the Workspace has no
        // plan assignment at all.

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', [$workspace->fresh()->uid, $business->fresh()->uid]))
            ->assertNotFound();
    }

    public function test_business_feature_disabled_denies_website_show(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::WebsiteGeneration, $workspace->owner_user_id);

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_workspace_override_deny_denies_website_show(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);

        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            PlatformFeature::WebsiteGeneration,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'test'
        );

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }

    public function test_suspended_plan_denies_website_show(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);

        app(EntitlementManager::class)->changePlanStatus(
            $workspace,
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'test'
        );

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.show', $this->routeParams($workspace, $business)))
            ->assertNotFound();
    }
}
