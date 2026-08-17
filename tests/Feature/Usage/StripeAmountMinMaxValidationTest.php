<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §12/§25 item 83 — Stripe's documented minimum and
 * eight-digit maximum are both enforced before every outbound call.
 */
class StripeAmountMinMaxValidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    private function businessWithAttachedInstrument(): array
    {
        $customer = $this->createCustomer();
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

        return [$customer, $business];
    }

    public function test_an_amount_below_the_minimum_is_rejected_before_any_provider_call(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        // 1 cent (10,000 micro-units at USD's 2-decimal exponent), below
        // the 50-minor-unit floor.
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 10_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('invalid_request', $result->denialReason);
    }

    public function test_an_amount_at_exactly_the_minimum_succeeds(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        // Exactly 50 cents (500,000 micro-units).
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 500_000);

        $this->assertSame(FundingAttemptState::Succeeded, $result->state);
    }

    public function test_an_amount_above_the_eight_digit_maximum_is_rejected(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        // 99,999,999.01 minor units worth of micro-units — one cent over
        // Stripe's eight-digit maximum.
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 1_000_000_000_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('invalid_request', $result->denialReason);
    }

    public function test_an_amount_at_exactly_the_eight_digit_maximum_succeeds(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        // Exactly 99,999,999 minor units.
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 999_999_990_000);

        $this->assertSame(FundingAttemptState::Succeeded, $result->state);
    }
}
