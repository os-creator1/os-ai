<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\AppConfig;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §18.1/§18.2/§25 item 112 (Correction Round 1) — end-
 * to-end proof that the dashboard's M3 integration (items 104-110) is
 * wired and reachable, not dead code.
 */
class UsageBillingDashboardStripeIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);

        config([
            'services.stripe.key' => 'pk_test_fixture',
            'services.stripe.secret' => 'sk_test_fixture',
            'services.stripe.webhook.secret' => 'whsec_fixture',
            'services.stripe.mode' => 'test',
        ]);
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
            'first_name' => 'M3Fixture', 'last_name' => 'Admin', 'email' => 'm3fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    public function test_dashboard_renders_payment_method_and_funding_history_when_provider_is_configured(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(\App\Repositories\Contracts\BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $response = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $response->assertDontSee('Payment methods and top-ups are not yet configured.');
        // The payment-method partial (item 67) is genuinely included and rendering.
        $response->assertSee('Visa', false);
        $response->assertSee('4242');
        // The funding-history card (item 104) genuinely paginates real rows.
        $response->assertSee('Funding history');
        $response->assertSee('manual_top_up');
        $response->assertSee('succeeded');

        $html = $response->getContent();
        $this->assertStringNotContainsString($providerCustomer->provider_customer_id, $html, 'The raw provider customer id must never be rendered.');
        $this->assertStringNotContainsString('sk_test_fixture', $html, 'The Stripe secret key must never be rendered.');
        $this->assertStringNotContainsString('whsec_fixture', $html, 'The webhook signing secret must never be rendered.');
    }

    public function test_dashboard_still_renders_the_honest_placeholder_when_provider_is_not_configured(): void
    {
        config([
            'services.stripe.key' => null,
            'services.stripe.secret' => null,
            'services.stripe.webhook.secret' => null,
        ]);

        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        $response = $this->get(route('customer.workspaces.businesses.usage-billing.show', [$workspace->uid, $business->uid]))
            ->assertOk();

        $response->assertSee('Payment methods and top-ups are not yet configured.');
        $response->assertDontSee('Set up payment method');
    }

    public function test_every_new_route_is_isolated_by_the_existing_resolveviewablebusiness_pattern(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($ownerCustomer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');

        $unrelated = $this->actingAsHttpCustomer();

        $this->post(route('customer.workspaces.businesses.usage-billing.payment-method.setup-intent', [$workspace->uid, $business->uid]))
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.usage-billing.top-up.initiate', [$workspace->uid, $business->uid]), ['amount_micro' => 1_000_000])
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.usage-billing.auto-recharge.configure', [$workspace->uid, $business->uid]), ['auto_recharge_enabled' => '0'])
            ->assertNotFound();
    }

    public function test_no_live_stripe_network_call_occurs_anywhere_in_this_flow(): void
    {
        // The FakePaymentProviderGateway bound in setUp() never makes a
        // real HTTP call by construction (M3 contract §8) — this test
        // simply proves the full flow completes without requiring one.
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 1_000_000);

        $this->assertNotNull($result->fundingAttemptId);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::ProviderPending, $result->state);

        // Proves the entire flow — not only initiation — completes
        // without a live call: confirmation, too, only ever reaches the
        // FakePaymentProviderGateway bound above.
        $attempt = app(\App\Repositories\Contracts\BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $confirmed = app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Succeeded, $confirmed->state);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §6 — proves the
     * controller genuinely redirects the browser to Stripe's own hosted
     * Checkout URL rather than completing synchronously back to the
     * dashboard.
     */
    public function test_initiating_a_top_up_redirects_to_the_hosted_checkout_url(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $response = $this->post(route('customer.workspaces.businesses.usage-billing.top-up.initiate', [$workspace->uid, $business->uid]), ['amount_micro' => 1_000_000]);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://checkout.fake.stripe.test/', $response->headers->get('Location'));
    }
}
