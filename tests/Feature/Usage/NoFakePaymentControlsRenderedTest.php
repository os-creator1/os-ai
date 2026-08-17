<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\UsageWalletManager;
use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §5, extended RFC-005 M3 contract §18.2 (Correction
 * Round 1, item 111) — the M2-era method below is scoped to the
 * providerConfigured === false case only, preserving its exact existing
 * assertions unchanged; the new method proves the inverse: when
 * providerConfigured === true and a real payment instrument/funding
 * history exist, the placeholder is genuinely gone from the
 * payment-methods card and the real controls genuinely render. Together
 * they prove the placeholder is removed exactly where, and only where,
 * real functionality replaces it.
 */
class NoFakePaymentControlsRenderedTest extends TestCase
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

    public function test_dashboard_shows_the_honest_unavailable_message_and_no_fake_controls(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $response = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $response->assertSee('Payment methods and top-ups are not yet configured.');

        $html = $response->getContent();

        foreach (['Add card', 'Add Card', 'Stripe Checkout', 'Top up', 'Top-up', 'Refund', 'Auto-recharge', 'Enable auto-recharge', 'Invoice', 'Receipt', 'Buy add-on', 'Purchase add-on', 'Buy additional slot', 'Purchase slot'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Dashboard must not render a fake payment control containing \"{$forbidden}\".");
        }
    }

    /**
     * M3 contract §18.2/§25 item 111 (Correction Round 1) — when the
     * provider is genuinely configured, the M2 placeholder is genuinely
     * absent and the real payment-method/top-up/auto-recharge controls
     * genuinely render.
     */
    public function test_dashboard_renders_real_m3_controls_when_provider_is_configured(): void
    {
        config([
            'services.stripe.key' => 'pk_test_fixture',
            'services.stripe.secret' => 'sk_test_fixture',
            'services.stripe.webhook.secret' => 'whsec_fixture',
            'services.stripe.mode' => 'test',
        ]);
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());

        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $response = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $response->assertDontSee('Payment methods and top-ups are not yet configured.');
        $response->assertSee('Set up payment method');
        $response->assertSee('Initiate top-up');
        $response->assertSee('Enable auto-recharge');
    }
}
