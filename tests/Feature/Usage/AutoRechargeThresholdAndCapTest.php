<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §15/§25 item 94 — threshold-crossing triggers
 * evaluation; the monthly cap denies a recharge that would exceed it; no
 * default value is asserted anywhere in this file (every fixture supplies
 * its own explicit threshold/amount/cap).
 */
class AutoRechargeThresholdAndCapTest extends TestCase
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

    /**
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function businessWithAutoRechargeConfigured(?string $thresholdMicro, ?string $amountMicro, ?string $capMicro): array
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

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, $thresholdMicro, $amountMicro, $capMicro, (int) $customer->user_id);

        return [$customer, $business];
    }

    public function test_crossing_the_configured_threshold_triggers_a_successful_recharge(): void
    {
        [, $business] = $this->businessWithAutoRechargeConfigured('2000000', '3000000', null);

        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => '1000000']);

        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('4000000', (string) $wallet->available_balance_micro);
        $this->assertSame('3000000', (string) $wallet->recharged_this_period_micro);
    }

    public function test_balance_at_or_above_threshold_does_not_trigger_a_recharge(): void
    {
        [, $business] = $this->businessWithAutoRechargeConfigured('2000000', '3000000', null);

        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => '2000000']);

        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('2000000', (string) $wallet->available_balance_micro);
        $this->assertSame('0', (string) $wallet->recharged_this_period_micro);
    }

    public function test_a_recharge_that_would_exceed_the_monthly_cap_is_denied(): void
    {
        [, $business] = $this->businessWithAutoRechargeConfigured('2000000', '3000000', '4000000');

        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => '1000000', 'recharged_this_period_micro' => '2000000']);

        // recharged_this_period_micro (2,000,000) + amount (3,000,000) =
        // 5,000,000, which exceeds the configured 4,000,000 cap.
        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('1000000', (string) $wallet->available_balance_micro, 'Balance must be unchanged when the cap would be exceeded.');
        $this->assertSame('2000000', (string) $wallet->recharged_this_period_micro);
    }

    public function test_a_recharge_within_the_monthly_cap_still_succeeds(): void
    {
        [, $business] = $this->businessWithAutoRechargeConfigured('2000000', '3000000', '5000000');

        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => '1000000', 'recharged_this_period_micro' => '2000000']);

        EvaluateBusinessAutoRecharge::dispatch((int) $business->id);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('4000000', (string) $wallet->available_balance_micro);
        $this->assertSame('5000000', (string) $wallet->recharged_this_period_micro);
    }
}
