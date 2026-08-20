<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * M4 contract §21 — the "attempt succeeded, purchase still pending"
 * replay hole: a funding attempt already Succeeded, with its linked
 * purchase deliberately left pending (simulating a crash between the two
 * writes), must still reach completed exactly once when
 * confirmAttemptFromWebhook()/confirmAttemptFromReturn() is called again.
 * ManualTopUp/AutoRecharge's own already-Succeeded no-op behavior remains
 * unchanged.
 */
class AddonPurchaseTransitionAuditTest extends TestCase
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

    private function businessWithInstrumentAndCatalogRow(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-addon', 'display_name' => 'Fixture Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'wallet_credit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $business];
    }

    public function test_a_crash_between_attempt_succeeded_and_purchase_completed_is_repaired_by_a_later_webhook(): void
    {
        [$customer, $business] = $this->businessWithInstrumentAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $this->assertSame(FundingAttemptState::Succeeded, $result->state);

        // Simulate the crash: force the purchase back to pending after
        // the attempt has already succeeded.
        $purchaseRepo = app(BusinessUsageAddonPurchaseRepository::class);
        $purchase = $purchaseRepo->findById($result->addonPurchaseId);
        $purchaseRepo->update($purchase, ['status' => AddonPurchaseStatus::Pending->value, 'completed_at' => null]);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        $manager->confirmAttemptFromWebhook($attempt, $event);

        $repaired = $purchaseRepo->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $repaired->status);
        $this->assertNotNull($repaired->completed_at);

        // Two legitimate audit rows: the original synchronous completion
        // (later corrupted away by the simulated crash) and this repair's
        // own completion — the wallet credit itself, the only side effect
        // that must never duplicate, is asserted separately below.
        $transitions = app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($result->addonPurchaseId);
        $this->assertCount(2, $transitions);

        $creditEntryCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $result->fundingAttemptId)
            ->where('correlation_key', $attempt->local_idempotency_key.':credit')
            ->count();
        $this->assertSame(1, $creditEntryCount);
    }

    public function test_a_duplicate_webhook_against_an_already_completed_purchase_is_idempotent(): void
    {
        [$customer, $business] = $this->businessWithInstrumentAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        // First delivery already completed the purchase synchronously via
        // confirmSucceeded() inside initiateAddonPurchase() itself; a
        // duplicate webhook delivery must be a pure no-op.
        $manager->confirmAttemptFromWebhook($attempt, $event);

        $purchaseRepo = app(BusinessUsageAddonPurchaseRepository::class);
        $purchase = $purchaseRepo->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);

        $transitions = app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($result->addonPurchaseId);
        $this->assertCount(1, $transitions);
    }

    public function test_manual_top_up_already_succeeded_no_op_behavior_is_unchanged(): void
    {
        [$customer, $business] = $this->businessWithInstrumentAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 2_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $balanceBefore = $wallet->available_balance_micro;

        $second = $manager->confirmAttemptFromReturn($attempt);
        $this->assertSame(FundingAttemptState::Succeeded, $second->state);

        $walletAfter = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame($balanceBefore, $walletAfter->available_balance_micro);
    }
}
