<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository;
use App\Jobs\Usage\SendReceiptNotification;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
 *
 * RFC-005 Funding Provider-Flow Correction Contract §17 — corrected for
 * the Checkout-Session-backed AddonPurchase/ManualTopUp lifecycle: neither
 * reaches Succeeded synchronously inside the initiating call any longer,
 * so every crash/replay scenario now drives an explicit confirmation step
 * first. The already-Succeeded early-return branch itself (the actual
 * subject of every test below) is otherwise completely untouched by this
 * correction — it never performs a provider retrieval of any kind.
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

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §9 — neither
     * ManualTopUp nor AddonPurchase requires a pre-saved default
     * instrument; only a resolved provider customer (Option 1, unchanged).
     */
    private function businessWithProviderCustomerAndCatalogRow(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-addon', 'display_name' => 'Fixture Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'wallet_credit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $business];
    }

    /**
     * Mirrors TopUpStateMachineTest's own identical helper — registers a
     * deterministic complete/paid CheckoutSessionResult plus a matching
     * PaymentMethodResult for an attempt's own persisted Session id.
     */
    private function registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt): void
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_'.uniqid();

        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId,
            $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '4242', 12, 2030,
        ));

        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_verified_'.uniqid(),
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_addon_verified',
            'ch_fake_addon_verified',
        ));
    }

    public function test_a_crash_between_attempt_succeeded_and_purchase_completed_is_repaired_by_a_later_webhook(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $this->assertSame(FundingAttemptState::ProviderPending, $result->state);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $confirmed = $manager->confirmAttemptFromReturn($attempt);
        $this->assertSame(FundingAttemptState::Succeeded, $confirmed->state);

        // Simulate the crash: force the purchase back to pending after
        // the attempt has already succeeded.
        $purchaseRepo = app(BusinessUsageAddonPurchaseRepository::class);
        $purchase = $purchaseRepo->findById($result->addonPurchaseId);
        $purchaseRepo->update($purchase, ['status' => AddonPurchaseStatus::Pending->value, 'completed_at' => null]);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
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

    /**
     * M4 contract §21 (Correction Round 2 §C) — the other crash direction:
     * the attempt reached Succeeded but finalizeAddonPurchaseIfPending()
     * never ran its downstream effects at all yet (crash strictly before
     * the ledger credit). Directly rolls back the ledger row/wallet
     * balance/purchase status to that exact pre-credit state, then proves
     * a later webhook both credits (exactly once) and completes.
     */
    public function test_a_crash_before_any_credit_or_completion_is_repaired_by_a_later_webhook(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $confirmed = $manager->confirmAttemptFromReturn($attempt);
        $this->assertSame(FundingAttemptState::Succeeded, $confirmed->state);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $correlationKey = $attempt->local_idempotency_key.':credit';

        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('correlation_key', $correlationKey)->first();
        $this->assertNotNull($ledgerEntry, 'Fixture assumption: the synchronous confirmation already credited.');

        // Roll back to exactly the pre-credit, pre-completion state: no
        // ledger row, no wallet effect, purchase still pending. The
        // receipt row the synchronous confirmation's own sync-queued
        // SendReceiptNotification already created (Receipt Boundary
        // Correction Contract §3) must also be rolled back first — its
        // ledger_entry_id FK otherwise blocks the ledger row's own
        // deletion below.
        DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntry->id)->delete();
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => DB::raw('available_balance_micro - '.(int) $ledgerEntry->available_delta_micro),
        ]);
        DB::table('business_usage_ledger_entries')->where('id', $ledgerEntry->id)->delete();

        $purchaseRepo = app(BusinessUsageAddonPurchaseRepository::class);
        $purchase = $purchaseRepo->findById($result->addonPurchaseId);
        $purchaseRepo->update($purchase, ['status' => AddonPurchaseStatus::Pending->value, 'completed_at' => null]);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        $manager->confirmAttemptFromWebhook($attempt, $event);

        $repaired = $purchaseRepo->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $repaired->status);
        $this->assertNotNull($repaired->completed_at);

        $creditEntryCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $result->fundingAttemptId)
            ->where('correlation_key', $correlationKey)
            ->count();
        $this->assertSame(1, $creditEntryCount, 'Exactly one credit — the replay, since the original was rolled back — never zero, never two.');
    }

    public function test_a_duplicate_webhook_against_an_already_completed_purchase_is_idempotent(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $manager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        // The purchase already completed via the earlier
        // confirmAttemptFromReturn(); a duplicate webhook delivery for the
        // same already-Succeeded attempt must be a pure no-op.
        $manager->confirmAttemptFromWebhook($attempt, $event);

        $purchaseRepo = app(BusinessUsageAddonPurchaseRepository::class);
        $purchase = $purchaseRepo->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);

        $transitions = app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($result->addonPurchaseId);
        $this->assertCount(1, $transitions);
    }

    public function test_manual_top_up_already_succeeded_no_op_behavior_is_unchanged(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateTopUp($business, $customer->user_id, 2_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $manager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $balanceBefore = $wallet->available_balance_micro;

        $second = $manager->confirmAttemptFromReturn($attempt);
        $this->assertSame(FundingAttemptState::Succeeded, $second->state);

        $walletAfter = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame($balanceBefore, $walletAfter->available_balance_micro);
    }

    public function test_addon_purchase_creates_a_checkout_session_not_a_payment_intent(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();

        $result = app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);

        $this->assertSame(FundingAttemptState::ProviderPending, $result->state);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertStringStartsWith('cs_fake_', (string) $attempt->provider_session_or_intent_reference);
        $this->assertCount(1, $this->gateway->createCheckoutSessionCalls);
    }

    public function test_checkout_session_completed_webhook_confirms_an_addon_purchase(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $attempt->provider_session_or_intent_reference,
                'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
                'amount_total' => $manager->expectedMinorUnitsFor($attempt),
                'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
                'customer' => $attempt->provider_customer_external_id_snapshot,
            ]],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);

        $event = PaymentProviderEvent::query()->latest('id')->first();
        $this->assertSame('processed', $event->state->value);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);
    }

    public function test_initiate_addon_purchase_creates_a_checkout_session_and_returns_a_hosted_redirect_url(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $default = app(\App\Repositories\Contracts\BusinessPaymentInstrumentRepository::class)->findDefaultForProviderCustomer((int) $providerCustomer->id);
        $this->assertNull($default, 'Fixture assumption: no instrument exists yet.');

        $result = app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);

        $this->assertSame(FundingAttemptState::ProviderPending, $result->state);
        $this->assertNotNull($result->redirectUrl);
        $this->assertStringStartsWith('https://checkout.fake.stripe.test/', $result->redirectUrl);

        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Pending, $purchase->status, 'No fulfillment may occur merely from Session creation.');

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(0, (int) $wallet->available_balance_micro);
    }

    public function test_create_checkout_session_records_setup_future_usage_false_for_addon_purchase(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();

        app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);

        $this->assertCount(1, $this->gateway->createCheckoutSessionCalls);
        $this->assertFalse($this->gateway->createCheckoutSessionCalls[0]['setupFutureUsageOffSession']);
        $this->assertSame('Fixture Add-on', $this->gateway->createCheckoutSessionCalls[0]['lineItemName']);
    }

    /**
     * Receipt Boundary Correction Contract §4 row 3 — a wallet_credit
     * add-on purchase calls creditFromFunding() (via
     * finalizeAddonPurchaseIfPending()) exactly like a ManualTopUp, so it
     * is receipt-eligible.
     */
    public function test_wallet_credit_addon_purchase_dispatches_exactly_one_send_receipt_notification(): void
    {
        Queue::fake();

        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $manager = app(UsageBillingCheckoutManager::class);

        $result = $manager->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $manager->confirmAttemptFromReturn($attempt);

        Queue::assertPushed(SendReceiptNotification::class, 1);
    }

    /**
     * Receipt Boundary Correction Contract §4 row 4 — direct_deliverable
     * never calls creditFromFunding() at all (pure state-machine
     * completion, no wallet mutation), so no receipt row and no
     * notification are mechanically possible.
     */
    public function test_direct_deliverable_addon_purchase_dispatches_no_receipt_notification(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-deliverable-addon', 'display_name' => 'Fixture Deliverable Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'direct_deliverable', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateAddonPurchase($business, 'fixture-deliverable-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $manager->confirmAttemptFromReturn($attempt);

        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);

        Queue::assertNotPushed(SendReceiptNotification::class);

        $receiptCount = DB::table('business_billing_receipts')->where('business_id', $business->id)->count();
        $this->assertSame(0, $receiptCount);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.2 —
     * proves completeAddonPurchaseUnderLock()'s own uniform lock
     * protection for the fulfillment mode with no credit-based first-pass
     * filter at all: direct_deliverable never reaches creditFromFunding(),
     * so the row lock is the only available serialization point. Two
     * independently-fetched snapshots of the same attempt are confirmed
     * directly, no test-owned try/catch around either call, and the
     * funding-attempt-level outcome is asserted alongside the purchase-
     * level one.
     */
    public function test_a_genuinely_simultaneous_double_confirmation_of_a_direct_deliverable_addon_purchase_completes_exactly_once(): void
    {
        Queue::fake();
        Event::fake([BusinessFundingAttemptSucceeded::class]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-deliverable-race-addon', 'display_name' => 'Fixture Deliverable Race Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'direct_deliverable', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateAddonPurchase($business, 'fixture-deliverable-race-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);

        $winner = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $loser = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        $manager->confirmAttemptFromReturn($winner);
        $manager->confirmAttemptFromReturn($loser);

        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);
        $this->assertNotNull($purchase->completed_at);

        $transitions = app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($result->addonPurchaseId);
        $this->assertCount(1, $transitions);

        $ledgerCount = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->count();
        $this->assertSame(0, $ledgerCount, 'direct_deliverable performs no wallet mutation of any kind, regardless of the race.');

        Queue::assertNotPushed(SendReceiptNotification::class);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1);
    }
}
