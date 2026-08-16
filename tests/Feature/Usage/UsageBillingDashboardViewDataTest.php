<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\BillingStatusTransitionSource;
use App\Enums\Usage\WalletBillingStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §5/§9 — the dashboard accurately presents every
 * required state: initialized wallet, uninitialized wallet, debt,
 * suspended, and no-ledger-activity — all sourced from real M1/M2 state.
 */
class UsageBillingDashboardViewDataTest extends TestCase
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

    public function test_uninitialized_wallet_shows_the_honest_empty_state(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['currency_code' => 'ZZZ']));

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Usage tracking has not been set up for this Business yet.');
    }

    public function test_debt_balance_is_flagged(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 5_000_000]);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Outstanding debt');
    }

    public function test_suspended_billing_status_is_shown(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(UsageWalletManager::class)->setBillingStatus($business, WalletBillingStatus::Suspended, BillingStatusTransitionSource::AdminAction, (int) $admin->id, 'Test.');

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Suspended')
            ->assertSee("This Business's usage wallet is suspended. Contact support.", false);
    }

    public function test_no_ledger_activity_shows_the_honest_empty_state(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('No usage activity yet.');
    }

    public function test_no_spend_cap_configured_shows_honest_text(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('No spend cap configured');
    }
}
