<?php

namespace Tests\Feature\Security;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerContextSnapshot;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.7 — T-MSG-29, 55, 56, 57, 58, 59, 60, 61,
 * 62.
 *
 * Security Remediation Slice 0 closed the fail-open hole on this surface
 * with an owner-or-active-admin check (`canManage()`). The parent
 * contract's §6 row for this exact capability is narrower than that:
 * Agency **owner** ✅, Agency **admin** ❌. Slice 3 relocates the surface
 * and tightens that one clause to `WorkspaceCandidate::$isOwner`, with a
 * granted `manage_advanced_provider` permission stacked on top as an
 * independent requirement.
 *
 * These tests hold the tightening to both of its promises at once: it must
 * narrow (every actor Slice 0 denied is still denied, §4.7), and it must
 * not break (the owner still gets in). Neither of the two requirements may
 * substitute for the other, so each is also asserted alone.
 *
 * `MessagingProviderAuthorizationTest` in this same directory continues to
 * own Slice 0's original matrix; this file is additive and asserts the
 * clauses Slice 0 did not have.
 */
class RelocatedAdvancedProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private int $platformAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId = User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // T-MSG-55 — the positive case: the tightening narrows, not breaks
    // -----------------------------------------------------------------

    public function test_the_workspace_owner_entitled_and_permitted_retains_full_access(): void
    {
        [$owner, $business] = $this->agencyTenant();
        $this->authenticateAsCustomer($owner, ['view_numbers', 'manage_advanced_provider']);

        $this->assertGuardAllowsAccess(
            $this->get($this->relocated('index', $business)),
        );
    }

    // -----------------------------------------------------------------
    // T-MSG-29 — BOTH requirements, neither substituting for the other
    // -----------------------------------------------------------------

    public function test_the_owner_without_the_granted_permission_is_denied(): void
    {
        [$owner, $business] = $this->agencyTenant();

        // Ownership, Agency tier and the entitlement are all present.
        // Only manage_advanced_provider is missing.
        $this->authenticateAsCustomer($owner, ['view_numbers']);

        $this->get($this->relocated('index', $business))->assertStatus(404);
    }

    public function test_the_granted_permission_without_ownership_is_denied(): void
    {
        [, $business, $admin] = $this->agencyTenantWithAdmin();

        $this->authenticateAsCustomer($admin, ['view_numbers', 'manage_advanced_provider']);

        $this->get($this->relocated('index', $business))->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // T-MSG-56 — the one actor this tightening newly denies
    // -----------------------------------------------------------------

    public function test_an_active_agency_admin_who_is_not_the_owner_is_denied_on_every_method(): void
    {
        [, $business, $admin, $connection] = $this->agencyTenantWithAdmin(withConnection: true);

        // First prove this actor is exactly the one the tightening targets:
        // canManage() is true (Slice 0 would have admitted them) while
        // isOwner is false (§6's row marks Agency admin denied). Without
        // this, the test could be passing for an unrelated reason.
        $candidate = $this->workspaceCandidateFor($admin, $business);
        $this->assertTrue($candidate->canManage(), 'Fixture must be an actor Slice 0 admitted.');
        $this->assertFalse($candidate->isOwner, 'Fixture must not be the Workspace owner.');
        $this->assertTrue($candidate->isAgency(), 'Fixture must clear the tier clause too.');

        $this->authenticateAsCustomer($admin, ['view_numbers', 'manage_advanced_provider']);

        $this->assertEveryRelocatedMethodDenies($business, $connection);
    }

    // -----------------------------------------------------------------
    // T-MSG-57 / T-MSG-58 — staff, with and without the permission
    // -----------------------------------------------------------------

    public function test_ordinary_agency_staff_is_denied_with_and_without_the_permission(): void
    {
        [, $business, $staff, $connection] = $this->agencyTenantWithStaff(scope: 'all', withConnection: true);

        $this->authenticateAsCustomer($staff, ['view_numbers']);
        $this->get($this->relocated('index', $business))->assertStatus(404);

        // Granting the permission changes nothing: it is stacked on top of
        // ownership, never a substitute for it.
        $this->authenticateAsCustomer($staff, ['view_numbers', 'manage_advanced_provider']);
        $this->assertEveryRelocatedMethodDenies($business, $connection);
    }

    public function test_selected_scope_staff_actually_assigned_to_the_business_is_still_denied(): void
    {
        [, $business, $staff, $connection] = $this->agencyTenantWithStaff(
            scope: 'selected',
            withConnection: true,
            assignBusiness: true,
        );

        // The assignment row exists — this actor genuinely has access TO the
        // Business. Business-scope assignment is not Workspace ownership,
        // and the relocated surface requires the latter.
        $this->assertTrue(
            WorkspaceMembershipBusiness::query()
                ->whereIn('workspace_membership_id', WorkspaceMembership::query()
                    ->where('workspace_id', $business->workspace_id)
                    ->where('user_id', $staff->user_id)
                    ->pluck('id'))
                ->where('business_id', $business->id)
                ->exists(),
            'Fixture must genuinely be assigned to this Business.',
        );

        $this->authenticateAsCustomer($staff, ['view_numbers', 'manage_advanced_provider']);

        $this->assertEveryRelocatedMethodDenies($business, $connection);
    }

    // -----------------------------------------------------------------
    // T-MSG-59 — ownership alone is not enough (unchanged from Slice 0)
    // -----------------------------------------------------------------

    public function test_a_core_or_growth_tier_owner_is_denied_even_holding_the_permission(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business] = $this->tenantWithPlan($tier);

            $this->authenticateAsCustomer($owner, ['view_numbers', 'manage_advanced_provider']);

            $this->get($this->relocated('index', $business))
                ->assertStatus(404, "A {$tier->value}-tier owner must not reach the relocated surface.");
        }
    }

    // -----------------------------------------------------------------
    // T-MSG-60 — platform-owner access is a separate boundary entirely
    // -----------------------------------------------------------------

    public function test_a_platform_administrator_with_no_workspace_membership_is_denied_on_the_customer_route(): void
    {
        [, $business] = $this->agencyTenant();

        $admin = User::find($this->platformAdminId);
        $admin->email_verified_at = now();
        $admin->save();

        // §6's "Platform owner ✅" cell is satisfied through the separate
        // is_admin / EnsureUserIsAdministrator boundary on the Admin route
        // group — never by inferring platform status from this customer
        // route, from WorkspacePlanTier::Agency, or from canManage().
        $this->withSession(['permissions' => collect(['access_backend', 'view_numbers', 'manage_advanced_provider'])]);
        $this->actingAs($admin);

        $this->get($this->relocated('index', $business))->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // T-MSG-61 — an inactive Workspace fails closed for its own owner
    // -----------------------------------------------------------------

    public function test_the_owner_of_an_inactive_workspace_is_denied(): void
    {
        [$owner, $business] = $this->agencyTenant();

        $workspace = $business->workspace;
        $workspace->is_active = false;
        $workspace->save();

        $this->authenticateAsCustomer($owner, ['view_numbers', 'manage_advanced_provider']);

        $this->get($this->relocated('index', $business))->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // T-MSG-62 — cross-tenant direct URL, GET and mutation alike
    // -----------------------------------------------------------------

    public function test_an_owner_of_another_workspace_is_denied_on_every_method(): void
    {
        [, $victimBusiness] = $this->agencyTenant();
        $victimConnection = $this->createDedicatedConnection($victimBusiness, SendingServer::TYPE_TWILIO);

        // A legitimate Agency owner in good standing — of a DIFFERENT
        // Workspace. Everything about the actor passes except tenancy.
        [$attacker] = $this->agencyTenant();

        $this->authenticateAsCustomer($attacker, ['view_numbers', 'manage_advanced_provider']);

        $this->assertEveryRelocatedMethodDenies($victimBusiness, $victimConnection);
    }

    // -----------------------------------------------------------------
    // Shared assertions and fixtures
    // -----------------------------------------------------------------

    /**
     * Every method the relocated surface exposes, GET and mutation alike,
     * plus proof that no mutation left a trace behind.
     */
    private function assertEveryRelocatedMethodDenies(Business $business, CustomerBasedSendingServer $connection): void
    {
        $originalServer = SendingServer::find($connection->sending_server)->toArray();
        $originalStatus = $connection->fresh()->status;

        $this->get($this->relocated('index', $business))->assertStatus(404);
        $this->get($this->relocated('connect', $business, [SendingServer::TYPE_TWILIO]))->assertStatus(404);
        $this->get($this->relocated('connections.show', $business, [$connection->uid]))->assertStatus(404);

        $this->post($this->relocated('connect', $business, [SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'MUST_NOT_BE_SAVED',
            'auth_token' => 'must_not_be_saved',
        ])->assertStatus(404);

        $this->put($this->relocated('connections.update', $business, [$connection->uid]), [
            'account_sid' => 'MUST_NOT_BE_SAVED',
            'auth_token' => 'must_not_be_saved',
        ])->assertStatus(404);

        $this->post($this->relocated('connections.enable', $business, [$connection->uid]))->assertStatus(404);
        $this->post($this->relocated('connections.disable', $business, [$connection->uid]))->assertStatus(404);

        $this->assertDatabaseMissing('sending_servers', ['account_sid' => 'MUST_NOT_BE_SAVED']);
        $this->assertSame($originalServer, SendingServer::find($connection->sending_server)->fresh()->toArray());
        $this->assertSame($originalStatus, $connection->fresh()->status);
    }

    /**
     * The relocated URL. §4.7 moved the PATH; the route names are
     * deliberately unchanged (see routes/customer.php), so this helper
     * asserts the new path explicitly rather than trusting the name alone
     * — otherwise a silent move back to `.../channels` would go unnoticed
     * by every test in this file.
     */
    private function relocated(string $suffix, Business $business, array $extra = []): string
    {
        $url = route(
            "customer.workspaces.businesses.channels.{$suffix}",
            [$business->workspace->uid, $business->uid, ...$extra],
        );

        $this->assertStringContainsString('/settings/advanced', $url);
        $this->assertStringNotContainsString('/channels', $url);

        return $url;
    }

    private function workspaceCandidateFor(Customer $actor, Business $business): \App\Library\Navigation\WorkspaceCandidate
    {
        $candidates = app(CustomerContextSnapshot::class)->forUser((int) $actor->user_id);

        foreach ($candidates as $candidate) {
            if ($candidate->id === (int) $business->workspace_id) {
                return $candidate;
            }
        }

        $this->fail('The actor cannot resolve the Workspace at all — wrong fixture for this assertion.');
    }

    /** @return array{0: Customer, 1: Business} */
    private function agencyTenant(): array
    {
        return $this->tenantWithPlan(WorkspacePlanTier::Agency);
    }

    /** @return array{0: Customer, 1: Business} */
    private function tenantWithPlan(WorkspacePlanTier $tier): array
    {
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        app(EntitlementManager::class)->assignFirstPlan(
            $business->workspace,
            $tier,
            $this->platformAdminId,
            'Test fixture assignment.',
            true,
            0,
        );

        // Businesses start in Draft; the guard also requires an active
        // Business, so every denial below must come from the dimension the
        // test targets, never from an incidentally-Draft fixture.
        app(BusinessRepository::class)->updateStatus($business, BusinessStatus::Active);

        return [$tenant, $business->fresh()];
    }

    /** @return array{0: Customer, 1: Business, 2: Customer, 3: CustomerBasedSendingServer|null} */
    private function agencyTenantWithAdmin(bool $withConnection = false): array
    {
        [$owner, $business] = $this->agencyTenant();

        $admin = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $admin->user_id,
            'role' => 'admin',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        return [
            $owner,
            $business,
            $admin,
            $withConnection ? $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO) : null,
        ];
    }

    /** @return array{0: Customer, 1: Business, 2: Customer, 3: CustomerBasedSendingServer|null} */
    private function agencyTenantWithStaff(string $scope, bool $withConnection = false, bool $assignBusiness = false): array
    {
        [$owner, $business] = $this->agencyTenant();

        $staff = $this->createCustomer();
        $membership = WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staff->user_id,
            'role' => 'staff',
            'business_access_scope' => $scope,
            'is_active' => true,
        ]);

        if ($assignBusiness) {
            WorkspaceMembershipBusiness::create([
                'workspace_membership_id' => $membership->id,
                'business_id' => $business->id,
            ]);
        }

        return [
            $owner,
            $business,
            $staff,
            $withConnection ? $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO) : null,
        ];
    }

    private function createDedicatedConnection(Business $business, string $provider): CustomerBasedSendingServer
    {
        $sendingServer = SendingServer::create([
            'name' => $provider,
            'settings' => $provider,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $business->customer_id,
            'account_sid' => 'AC_DEFAULT',
            'auth_token' => 'default_token',
        ]);

        return CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server' => $sendingServer->id,
            'status' => true,
        ]);
    }

    /**
     * A denied actor is aborted with 404 before any view is touched, so 404
     * is always the unambiguous "the guard blocked this" signal. An allowed
     * actor reaches the view layer, which in this sandbox can fail on
     * pre-existing, unrelated missing frontend build artifacts — reproduced
     * identically on unmodified origin/main. Mirrors
     * MessagingProviderAuthorizationTest's established helper rather than
     * inventing a second convention.
     */
    private function assertGuardAllowsAccess(TestResponse $response): void
    {
        $this->assertNotSame(404, $response->getStatusCode(), 'The guard denied an actor expected to pass.');

        if ($response->getStatusCode() === 200) {
            return;
        }

        $message = $response->exception?->getMessage() ?? '';

        $this->assertTrue(
            str_contains($message, 'Unable to locate Mix file') || str_contains($message, 'IconsManifest'),
            "Expected only the pre-existing asset-build gap reproduced on unmodified origin/main, got: {$message}",
        );
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions = []): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
