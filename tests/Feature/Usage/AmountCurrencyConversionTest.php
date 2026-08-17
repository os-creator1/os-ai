<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\ProviderInvalidRequestException;
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
 * RFC-005 M3 contract §12/§25 item 82 — bcRoundHalfUp() correctness at
 * representative and boundary values, and the corrected (Correction
 * Round 1) currency-exponent map: never hard-coded to USD's own divisor,
 * fail-closed for an unrecognized currency code.
 */
class AmountCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        Currency::create(['name' => 'Japanese Yen', 'code' => 'JPY', 'format' => '¥', 'status' => true]);
        Currency::create(['name' => 'Unsupported Test Currency', 'code' => 'ZZZ', 'format' => 'Z', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    public function test_bc_round_half_up_at_representative_and_boundary_values(): void
    {
        $this->assertSame('1', UsageWalletManager::bcRoundHalfUp('1000000', '1000000', 0));
        $this->assertSame('2', UsageWalletManager::bcRoundHalfUp('1500000', '1000000', 0));
        $this->assertSame('1', UsageWalletManager::bcRoundHalfUp('1499999', '1000000', 0));
        $this->assertSame('0', UsageWalletManager::bcRoundHalfUp('0', '1000000', 0));
        $this->assertSame('100', UsageWalletManager::bcRoundHalfUp('99999999', '1000000', 0));
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

    private function businessWithAttachedInstrument(string $currencyCode): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['currency_code' => $currencyCode]));
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

    public function test_conversion_is_never_hard_coded_to_usd_and_succeeds_for_a_zero_decimal_currency(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument('JPY');

        // JPY's exponent is 0, so 100,000,000 micro-units converts to 100
        // minor units (whole yen) — well above the 50-minor-unit floor.
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 100_000_000);

        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Succeeded, $result->state);
    }

    public function test_conversion_fails_closed_for_an_unrecognized_currency_code(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument('ZZZ');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 1_000_000);

        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Failed, $result->state);
        $this->assertSame('invalid_request', $result->denialReason);
    }
}
