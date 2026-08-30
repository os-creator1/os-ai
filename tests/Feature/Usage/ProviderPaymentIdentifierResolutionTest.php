<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Library\Entitlement\EntitlementManager;
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
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §3 — the two independent, independently-UNIQUE
 * provider-reference columns, and the dual-reference ambiguity/resolution
 * routing they enable for refund/dispute webhook events.
 */
class ProviderPaymentIdentifierResolutionTest extends TestCase
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
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    /**
     * @return array{0: \App\Models\Customer, 1: \App\Models\Business}
     */
    private function businessWithProviderCustomer(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        return [$customer, $business];
    }

    private function manualTopUpAttempt(): BusinessFundingAttempt
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        return app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
    }

    private function registerVerifiedCheckoutOutcome(BusinessFundingAttempt $attempt, ?string $paymentIntentId = null, ?string $chargeId = null): void
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_'.uniqid();

        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));

        $paymentIntentId ??= 'pi_fake_'.uniqid();
        $chargeId ??= 'ch_fake_'.uniqid();

        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            $paymentIntentId,
            $paymentMethodId,
            'https://fake.stripe.test/receipts/'.$chargeId,
            $chargeId,
        ));
    }

    private function autoRechargeAttemptSetup(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        return [$customer, $business];
    }

    private function receivedEvent(string $eventType, array $object): PaymentProviderEvent
    {
        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => $eventType,
            'provider_object_id' => (string) ($object['id'] ?? 'obj_'.uniqid()),
            'payload_encrypted' => json_encode(['data' => ['object' => $object]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received',
            'attempts' => 0,
            'received_at' => now(),
        ]);
    }

    public function test_provider_payment_intent_reference_is_persisted_for_a_checkout_backed_success(): void
    {
        $attempt = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attempt, 'pi_fake_expected_intent');

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('pi_fake_expected_intent', $fresh->provider_payment_intent_reference);
    }

    public function test_provider_charge_reference_is_persisted_for_a_checkout_backed_success(): void
    {
        $attempt = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attempt, null, 'ch_fake_expected_charge');

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame('ch_fake_expected_charge', $fresh->provider_charge_reference);
    }

    public function test_provider_payment_intent_reference_is_persisted_for_an_auto_recharge_success(): void
    {
        [, $business] = $this->autoRechargeAttemptSetup();

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->assertNotNull($attempt->provider_payment_intent_reference);
        $this->assertSame($attempt->provider_session_or_intent_reference, $attempt->provider_payment_intent_reference);
    }

    public function test_provider_charge_reference_is_persisted_when_already_available_for_an_auto_recharge_success_via_sync_return(): void
    {
        [, $business] = $this->autoRechargeAttemptSetup();
        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertNotNull($fresh->provider_charge_reference);
    }

    public function test_provider_charge_reference_remains_null_for_an_auto_recharge_success_confirmed_via_the_ordinary_webhook_path(): void
    {
        [, $business] = $this->autoRechargeAttemptSetup();
        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_'.uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        app(UsageBillingCheckoutManager::class)->confirmAttemptFromWebhook($attempt, $event);

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertNull($fresh->provider_charge_reference);
        $this->assertNotNull($fresh->provider_payment_intent_reference);
    }

    public function test_an_event_resolving_by_both_payment_intent_and_charge_to_the_same_attempt_is_processed(): void
    {
        $attempt = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attempt, 'pi_fake_both_a', 'ch_fake_both_a');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $event = $this->receivedEvent('charge.refunded', [
            'id' => 'ch_fake_both_a',
            'payment_intent' => 'pi_fake_both_a',
            'amount_refunded' => 1_000_000,
            'currency' => 'usd',
        ]);

        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        $fresh = PaymentProviderEvent::find($event->id);
        $this->assertSame('processed', $fresh->state->value);
    }

    public function test_an_event_whose_payment_intent_and_charge_resolve_to_different_attempts_fails_closed_with_zero_mutation(): void
    {
        $attemptA = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attemptA, 'pi_fake_amb_a', 'ch_fake_amb_a');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attemptA);

        $attemptB = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attemptB, 'pi_fake_amb_b', 'ch_fake_amb_b');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attemptB);

        $event = $this->receivedEvent('charge.refunded', [
            'id' => 'ch_fake_amb_b',
            'payment_intent' => 'pi_fake_amb_a',
            'amount_refunded' => 1_000_000,
            'currency' => 'usd',
        ]);

        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        $fresh = PaymentProviderEvent::find($event->id);
        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('cross_reference_ambiguity', $fresh->last_error);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('entry_type', 'refund')->count());
    }

    public function test_an_event_resolving_only_by_charge_reference_is_processed(): void
    {
        $attempt = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attempt, 'pi_fake_only_charge', 'ch_fake_only_charge');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $event = $this->receivedEvent('charge.refunded', [
            'id' => 'ch_fake_only_charge',
            'payment_intent' => null,
            'amount_refunded' => 1_000_000,
            'currency' => 'usd',
        ]);

        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        $fresh = PaymentProviderEvent::find($event->id);
        $this->assertSame('processed', $fresh->state->value);
    }

    public function test_an_event_resolving_only_by_payment_intent_reference_is_processed(): void
    {
        $attempt = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attempt, 'pi_fake_only_intent', 'ch_fake_only_intent');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $event = $this->receivedEvent('charge.refunded', [
            'id' => 'ch_fake_unrelated_'.uniqid(),
            'payment_intent' => 'pi_fake_only_intent',
            'amount_refunded' => 1_000_000,
            'currency' => 'usd',
        ]);

        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        $fresh = PaymentProviderEvent::find($event->id);
        $this->assertSame('processed', $fresh->state->value);
    }

    public function test_an_event_resolving_by_neither_reference_fails_closed(): void
    {
        $event = $this->receivedEvent('charge.refunded', [
            'id' => 'ch_fake_nowhere_'.uniqid(),
            'payment_intent' => 'pi_fake_nowhere_'.uniqid(),
            'amount_refunded' => 1_000_000,
            'currency' => 'usd',
        ]);

        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        $fresh = PaymentProviderEvent::find($event->id);
        $this->assertSame('failed', $fresh->state->value);
        $this->assertSame('no_matching_local_record', $fresh->last_error);
    }

    public function test_both_provider_reference_columns_enforce_uniqueness(): void
    {
        $attemptA = $this->manualTopUpAttempt();
        $this->registerVerifiedCheckoutOutcome($attemptA, 'pi_fake_unique_a', 'ch_fake_unique_a');
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attemptA);

        $attemptB = $this->manualTopUpAttempt();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('business_funding_attempts')->where('id', $attemptB->id)->update([
            'provider_payment_intent_reference' => 'pi_fake_unique_a',
        ]);
    }

    public function test_the_auto_recharge_backfill_migration_copies_the_existing_local_reference_with_no_provider_call(): void
    {
        [, $business] = $this->autoRechargeAttemptSetup();
        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);

        // Simulate the pre-migration state (the backfill migration's own
        // job): null out the reference column this correction introduces,
        // exactly as an auto_recharge row would have looked before it ran.
        DB::table('business_funding_attempts')->where('id', $result->fundingAttemptId)->update([
            'provider_payment_intent_reference' => null,
        ]);

        $before = $this->gateway->retrievePaymentIntentCalls;

        DB::table('business_funding_attempts')
            ->where('purpose', 'auto_recharge')
            ->whereNull('provider_payment_intent_reference')
            ->whereNotNull('provider_session_or_intent_reference')
            ->update([
                'provider_payment_intent_reference' => DB::raw('provider_session_or_intent_reference'),
            ]);

        $fresh = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame($fresh->provider_session_or_intent_reference, $fresh->provider_payment_intent_reference);
        $this->assertSame($before, $this->gateway->retrievePaymentIntentCalls, 'The backfill must never make a provider call.');
    }
}
