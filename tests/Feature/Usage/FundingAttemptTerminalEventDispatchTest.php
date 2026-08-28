<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Events\Usage\BusinessFundingAttemptFailed;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §7 — exact
 * dispatch-point proofs for BusinessFundingAttemptSucceeded/Failed.
 *
 * The three "listener observes already-committed state" methods do NOT
 * use Event::fake() for BusinessFundingAttemptSucceeded — Event::fake()
 * only records a dispatch and never runs the real listener, so a database
 * assertion made later inside Event::assertDispatched()'s own callback
 * would prove nothing about ordering (it inspects end-of-test state, not
 * the state that existed the instant the event fired). Instead, a real
 * Event::listen() is registered before the confirmation call, and its own
 * assertion runs synchronously at genuine dispatch time.
 */
class FundingAttemptTerminalEventDispatchTest extends TestCase
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
     * Mirrors TopUpStateMachineTest's own businessWithProviderCustomer().
     *
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function businessWithProviderCustomer(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        return [$customer, $business];
    }

    /**
     * Mirrors AddonPurchaseTransitionAuditTest's own fixture.
     *
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function businessWithProviderCustomerAndCatalogRow(): array
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-addon', 'display_name' => 'Fixture Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'wallet_credit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $business];
    }

    /**
     * Mirrors AutoRechargeFailedPaymentRetryTest's own
     * createBusinessWithAutoRechargeConfigured() fixture.
     *
     * @return array{0: int, 1: int} business_id, owner_user_id
     */
    private function businessWithAutoRechargeConfigured(): array
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $setupIntent = app(PaymentInstrumentManager::class)->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        app(PaymentInstrumentManager::class)->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        app(UsageWalletManager::class)->configureAutoRecharge($business, true, '2000000', '3000000', null, $customer->user_id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => '1000000']);

        return [(int) $business->id, (int) $customer->user_id];
    }

    private function postCheckoutWebhook(string $eventType, array $object): void
    {
        $rawBody = json_encode([
            'id' => 'evt_'.uniqid(),
            'type' => $eventType,
            'data' => ['object' => $object],
        ]);

        $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody)->assertStatus(200);
    }

    public function test_succeeded_listener_observes_the_wallet_credit_already_committed_for_a_topup(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $listenerRan = false;
        Event::listen(BusinessFundingAttemptSucceeded::class, function (BusinessFundingAttemptSucceeded $event) use (&$listenerRan, $result) {
            $creditExists = DB::table('business_usage_ledger_entries')
                ->where('funding_attempt_id', $result->fundingAttemptId)
                ->where('correlation_key', 'like', '%:credit')
                ->exists();
            $this->assertTrue($creditExists, 'The wallet credit ledger entry must already exist when BusinessFundingAttemptSucceeded is observed.');
            $listenerRan = true;
        });

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $this->assertTrue($listenerRan, 'The BusinessFundingAttemptSucceeded listener must have run exactly once.');
    }

    public function test_succeeded_listener_observes_the_wallet_credit_already_committed_for_an_auto_recharge(): void
    {
        [$businessId] = $this->businessWithAutoRechargeConfigured();
        $this->gateway->paymentIntentOutcomes = ['*' => 'succeeded'];

        $listenerRan = false;
        Event::listen(BusinessFundingAttemptSucceeded::class, function (BusinessFundingAttemptSucceeded $event) use (&$listenerRan) {
            $creditExists = DB::table('business_usage_ledger_entries')
                ->where('funding_attempt_id', $event->fundingAttemptId)
                ->where('correlation_key', 'like', '%:credit')
                ->exists();
            $this->assertTrue($creditExists, 'The wallet credit ledger entry must already exist when BusinessFundingAttemptSucceeded is observed.');
            $listenerRan = true;
        });

        EvaluateBusinessAutoRecharge::dispatch($businessId);

        $this->assertTrue($listenerRan, 'The BusinessFundingAttemptSucceeded listener must have run exactly once.');
    }

    public function test_succeeded_listener_observes_the_addon_purchase_already_completed(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $result = app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, 'fixture-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $listenerRan = false;
        Event::listen(BusinessFundingAttemptSucceeded::class, function (BusinessFundingAttemptSucceeded $event) use (&$listenerRan, $result) {
            $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
            $this->assertSame('completed', $purchase->status->value, 'The addon purchase must already be completed when BusinessFundingAttemptSucceeded is observed.');
            $listenerRan = true;
        });

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $this->assertTrue($listenerRan, 'The BusinessFundingAttemptSucceeded listener must have run exactly once.');
    }

    public function test_failed_dispatches_immediately_after_the_transition_record(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        Event::fake([BusinessFundingAttemptFailed::class]);

        $this->postCheckoutWebhook('checkout.session.expired', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        Event::assertDispatchedTimes(BusinessFundingAttemptFailed::class, 1);
        Event::assertDispatched(BusinessFundingAttemptFailed::class, function (BusinessFundingAttemptFailed $event) use ($attempt) {
            return $event->fundingAttemptId === $attempt->id && $event->businessId === (int) $attempt->business_id;
        });
    }

    public function test_replay_of_an_already_succeeded_attempt_does_not_redispatch(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $manager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        Event::fake([BusinessFundingAttemptSucceeded::class]);

        $manager->confirmAttemptFromReturn($attempt);

        Event::assertNotDispatched(BusinessFundingAttemptSucceeded::class);
    }

    public function test_replay_of_an_already_terminal_failed_attempt_does_not_redispatch(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->postCheckoutWebhook('checkout.session.expired', [
            'id' => $attempt->provider_session_or_intent_reference,
            'metadata' => ['app_subject_kind' => 'funding_attempt', 'app_subject_id' => (string) $attempt->id, 'app_operation_id' => $attempt->local_idempotency_key],
            'amount_total' => $manager->expectedMinorUnitsFor($attempt),
            'currency' => strtolower($manager->expectedCurrencyCodeFor($attempt)),
            'customer' => $attempt->provider_customer_external_id_snapshot,
        ]);

        Event::fake([BusinessFundingAttemptFailed::class]);

        // markAttemptFailedFromWebhook() already early-returns on an
        // already-terminal (Succeeded/Failed/Canceled) attempt before
        // ever calling markFailed() — replaying the same expiry event
        // must not redispatch.
        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $event = \App\Models\PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_replay_'.uniqid(), 'event_type' => 'checkout.session.expired',
            'provider_object_id' => $freshAttempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $manager->markAttemptFailedFromWebhook($freshAttempt, 'checkout_session_expired', $event);

        Event::assertNotDispatched(BusinessFundingAttemptFailed::class);
    }
}
