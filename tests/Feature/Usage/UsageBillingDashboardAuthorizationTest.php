<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §7 — the full actor/action matrix for the customer
 * Usage & Billing dashboard: Workspace owner, active Admin, Staff, direct
 * Business owner, unrelated Workspace member, and anonymous. 404 (never
 * 403) for every unrelated/existence-leak case.
 */
class UsageBillingDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = $this->createWorkspace($owner);

        $admin = User::create([
            'first_name' => 'M2Fixture', 'last_name' => 'Admin', 'email' => 'm2fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    public function test_guest_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        // Matches this repository's own established guest-rejection
        // convention for customer routes (e.g.
        // WorkspaceBusinessCreationHttpTest::test_guest_is_rejected_by_store_business()).
        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertUnauthorized();
    }

    public function test_workspace_owner_can_view_the_dashboard(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_direct_business_owner_can_view_the_dashboard(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);

        $directOwner = $this->actingAsHttpCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_active_admin_with_scope_can_view_the_dashboard(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerCustomer, $workspace, $this->businessAttributes());

        $admin = $this->actingAsHttpCustomer();
        $this->createMembership($workspace, $admin->user, ['role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_staff_with_scope_can_view_but_not_change_the_spend_cap(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerCustomer, $workspace, $this->businessAttributes());

        $staff = $this->actingAsHttpCustomer();
        $this->createMembership($workspace, $staff->user, ['role' => WorkspaceMembershipRole::Staff, 'business_access_scope' => WorkspaceBusinessAccessScope::All]);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $this->post(route('customer.workspaces.businesses.usage-billing.spend-cap', [$workspace->uid, $business->uid]), ['monthly_spend_cap_micro' => 1000000])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
    }

    public function test_unrelated_workspace_member_gets_404(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerCustomer, $workspace, $this->businessAttributes());

        $this->actingAsHttpCustomer();

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertNotFound();
    }

    public function test_unknown_business_uid_gets_404(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, 'nonexistent-uid']))
            ->assertNotFound();
    }

    public function test_unknown_workspace_uid_gets_404(): void
    {
        $this->actingAsHttpCustomer();

        $this->get(route('customer.workspaces.businesses.usage-billing.show', ['nonexistent-uid', 'nonexistent-uid']))
            ->assertNotFound();
    }

    public function test_workspace_owner_can_change_the_payer_to_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $this->post(route('customer.workspaces.businesses.usage-billing.payer', [$workspace->uid, $business->uid]), ['payer_type' => 'workspace'])
            ->assertRedirect()
            ->assertSessionHas('flash_success');
    }

    public function test_direct_business_owner_cannot_set_payer_to_workspace(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);

        $directOwner = $this->actingAsHttpCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());

        $this->post(route('customer.workspaces.businesses.usage-billing.payer', [$workspace->uid, $business->uid]), ['payer_type' => 'workspace'])
            ->assertRedirect()
            ->assertSessionHas('flash_error');
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §6 — the new
     * top-up confirmation route's own Business-isolation check: an actor
     * who can view Business A cannot use Business A's own route to
     * confirm a funding attempt that actually belongs to Business B.
     * Response is 404 (never 403, RFC-005 §24), Business B's attempt
     * remains completely unchanged, and no provider confirmation call
     * ever reaches the gateway (proven indirectly — the attempt's own
     * state is unaffected, which could not be true had
     * confirmAttemptFromReturn() actually run).
     */
    public function test_top_up_confirm_route_rejects_a_cross_business_attempt(): void
    {
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());

        $ownerA = $this->actingAsHttpCustomer();
        $workspaceA = $this->entitledWorkspace($ownerA->user);
        $businessA = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerA, $workspaceA, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(BillingProfileManager::class)->changePayer($businessA, PayerType::Workspace, $ownerA->user_id, 'Test.');

        $ownerB = $this->createCustomer();
        $workspaceB = $this->entitledWorkspace($ownerB->user);
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerB, $workspaceB, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        app(BillingProfileManager::class)->changePayer($businessB, PayerType::Workspace, $ownerB->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($businessB, $ownerB->user_id);

        $resultB = app(UsageBillingCheckoutManager::class)->initiateTopUp($businessB, $ownerB->user_id, 1_000_000);
        $attemptB = app(BusinessFundingAttemptRepository::class)->findById($resultB->fundingAttemptId);
        $this->assertSame(FundingAttemptState::ProviderPending, $attemptB->state);

        // Business A's own actor attempts to confirm Business B's attempt
        // through Business A's own route.
        $this->get(route('customer.workspaces.businesses.usage-billing.top-up.confirm', [$workspaceA->uid, $businessA->uid, $attemptB->id]))
            ->assertNotFound();

        $freshAttemptB = app(BusinessFundingAttemptRepository::class)->findById($attemptB->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttemptB->state, 'Business B\'s attempt must remain completely unchanged — confirmAttemptFromReturn() must never have run.');
    }
}
