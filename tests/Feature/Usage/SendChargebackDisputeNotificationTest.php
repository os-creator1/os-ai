<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Jobs\Usage\ProcessPaymentProviderEvent;
use App\Jobs\Usage\SendChargebackDisputeNotification;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Usage\ChargebackDisputeNotification;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Remediation #6 §11 — the chargeback/dispute notification's own
 * honest at-most-one dispatch-decision guarantee, and its job-level
 * recipient resolution, mirroring SendLowBalanceNotificationTest's own
 * established pattern exactly.
 */
class SendChargebackDisputeNotificationTest extends TestCase
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

    private function attemptWithVerifiedSuccess(?string $paymentIntentId = null, ?string $chargeId = null): BusinessFundingAttempt
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $manager = app(UsageBillingCheckoutManager::class);
        $result = $manager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, $paymentIntentId ?? 'pi_fake_'.uniqid(), $paymentMethodId,
            'https://fake.stripe.test/receipts/'.($chargeId ?? 'ch_fake_'.uniqid()), $chargeId ?? 'ch_fake_'.uniqid(),
        ));

        $manager->confirmAttemptFromReturn($attempt);

        return app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
    }

    /**
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function directDeliverableBusiness(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-direct-'.uniqid(), 'display_name' => 'Fixture Direct Add-on', 'price_micro' => 1_000_000,
            'currency_id' => $currencyId, 'fulfillment_mode' => 'direct_deliverable', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $business];
    }

    private function disputeEvent(string $eventType, string $chargeId, array $balanceTransactions, ?string $disputeId = null, ?string $providerEventId = null): PaymentProviderEvent
    {
        $disputeId ??= 'dp_fake_'.uniqid();

        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(),
            'event_type' => $eventType,
            'provider_object_id' => $disputeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $disputeId, 'charge' => $chargeId, 'payment_intent' => null, 'currency' => 'usd',
                'status' => 'lost', 'balance_transactions' => $balanceTransactions,
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function refundEvent(string $chargeId, int $amountRefundedMinorUnits, ?string $providerEventId = null): PaymentProviderEvent
    {
        return PaymentProviderEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => $providerEventId ?? 'evt_'.uniqid(),
            'event_type' => 'charge.refunded',
            'provider_object_id' => $chargeId,
            'payload_encrypted' => json_encode(['data' => ['object' => [
                'id' => $chargeId, 'payment_intent' => null, 'amount_refunded' => $amountRefundedMinorUnits, 'currency' => 'usd',
            ]]]),
            'payload_hash' => hash('sha256', uniqid()),
            'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
    }

    private function process(PaymentProviderEvent $event): PaymentProviderEvent
    {
        app()->call([new ProcessPaymentProviderEvent($event->id), 'handle']);

        return PaymentProviderEvent::find($event->id);
    }

    public function test_the_dispatch_decision_is_made_only_after_the_outer_transaction_commits(): void
    {
        Queue::fake();
        $attempt = $this->attemptWithVerifiedSuccess(null, 'ch_fake_commit1');
        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_commit1', [
            ['id' => 'txn_commit1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('processed', $fresh->state->value);
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->where('entry_type', 'dispute_chargeback')->count());
        Queue::assertPushed(SendChargebackDisputeNotification::class, 1);
    }

    public function test_the_dispatch_decision_is_made_at_most_once_for_the_correlation_key_winner(): void
    {
        Queue::fake();
        $this->attemptWithVerifiedSuccess(null, 'ch_fake_winner1');
        $disputeId = 'dp_fake_winner1';
        $balanceTransactions = [
            ['id' => 'txn_winner1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ];

        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_winner1', $balanceTransactions, $disputeId));
        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_winner1', $balanceTransactions, $disputeId));

        Queue::assertPushed(SendChargebackDisputeNotification::class, 1);
    }

    public function test_no_dispatch_decision_is_made_for_a_replayed_withdrawal_event(): void
    {
        Queue::fake();
        $this->attemptWithVerifiedSuccess(null, 'ch_fake_replay1');
        $disputeId = 'dp_fake_replay1';
        $balanceTransactions = [
            ['id' => 'txn_replay1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ];

        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_replay1', $balanceTransactions, $disputeId));
        Queue::fake();

        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_replay1', $balanceTransactions, $disputeId));

        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    public function test_no_dispatch_decision_is_made_for_the_correlation_key_loser_of_a_concurrent_write(): void
    {
        Queue::fake();
        $attempt = $this->attemptWithVerifiedSuccess(null, 'ch_fake_loser1');

        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $attempt->business_id, 'wallet_id' => DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->value('id'),
            'funding_attempt_id' => $attempt->id, 'entry_type' => 'dispute_chargeback',
            'available_delta_micro' => 0, 'reserved_delta_micro' => 0, 'debt_delta_micro' => 0,
            'gross_amount_micro' => 1_000_000, 'currency_id' => Currency::query()->first()->id,
            'correlation_key' => 'dispute_chargeback:'.$attempt->id.':txn_loser1',
            'provider_reference' => 'dp_fake_loser1', 'reason' => 'Pre-seeded winner.', 'created_at' => now(),
        ]);

        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_loser1', [
            ['id' => 'txn_loser1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ], 'dp_fake_loser1');

        $this->process($event);

        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    public function test_no_dispatch_decision_is_made_when_the_outer_transaction_rolls_back(): void
    {
        Queue::fake();
        $attempt = $this->attemptWithVerifiedSuccess(null, 'ch_fake_rollback1');
        // Forces the transaction to throw after the ledger insert has
        // already executed (but not yet committed) inside the same
        // transaction — an invalid enum-backed value throws when the
        // Eloquent model's own billing_status cast is later read, proving
        // a genuine mid-transaction rollback, not merely "never started".
        DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->update(['billing_status' => 'not_a_real_status']);

        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_rollback1', [
            ['id' => 'txn_rollback1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ]);

        $fresh = $this->process($event);

        $this->assertSame('failed', $fresh->state->value);
        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    public function test_a_direct_deliverable_withdrawal_still_produces_a_dispatch_decision_despite_zero_wallet_deltas(): void
    {
        Queue::fake();
        [$customer, $business] = $this->directDeliverableBusiness();
        $addonKey = DB::table('business_usage_addon_catalog')->where('fulfillment_mode', 'direct_deliverable')->value('addon_key');
        $result = app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, $addonKey, $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_'.uniqid();
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030));
        $manager = app(UsageBillingCheckoutManager::class);
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_direct1', $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_direct1', 'ch_fake_direct1',
        ));
        $manager->confirmAttemptFromReturn($attempt);

        $event = $this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_direct1', [
            ['id' => 'txn_direct1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ]);

        $this->process($event);

        Queue::assertPushed(SendChargebackDisputeNotification::class, 1);
    }

    public function test_no_dispatch_decision_is_made_for_a_reinstatement(): void
    {
        Queue::fake();
        $this->attemptWithVerifiedSuccess(null, 'ch_fake_reinstate1');
        $disputeId = 'dp_fake_reinstate1';
        $this->process($this->disputeEvent('charge.dispute.funds_withdrawn', 'ch_fake_reinstate1', [
            ['id' => 'txn_reinstate1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
        ], $disputeId));
        Queue::fake();

        $this->process($this->disputeEvent('charge.dispute.funds_reinstated', 'ch_fake_reinstate1', [
            ['id' => 'txn_reinstate1', 'amount' => -1_000_000, 'currency' => 'usd', 'net' => -1_000_000, 'type' => 'adjustment'],
            ['id' => 'txn_reinstate1b', 'amount' => 1_000_000, 'currency' => 'usd', 'net' => 1_000_000, 'type' => 'adjustment'],
        ], $disputeId));

        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    public function test_no_dispatch_decision_is_made_for_a_policy_excess_refund_outcome(): void
    {
        Queue::fake();
        $attempt = $this->attemptWithVerifiedSuccess(null, 'ch_fake_policyexcess1');
        DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->update([
            'available_balance_micro' => 0, 'refundable_paid_available_micro' => 0,
        ]);

        $event = $this->refundEvent('ch_fake_policyexcess1', 5_000_000);
        $fresh = $this->process($event);

        $this->assertSame('processed', $fresh->state->value);
        $this->assertGreaterThan(0, $fresh->normalized_policy_excess_micro);
        Queue::assertNotPushed(SendChargebackDisputeNotification::class);
    }

    // --- Job-level recipient resolution ---

    private function ledgerEntryIdForDisputeChargeback(): int
    {
        $attempt = $this->attemptWithVerifiedSuccess(null, 'ch_fake_recipient_'.uniqid());
        $wallet = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $attempt->business_id);

        $ledgerEntry = app(UsageWalletManager::class)->applyDisputeWithdrawal(
            (int) $attempt->business_id, true, (int) $attempt->id, 1_000_000, 'dp_fake_recipient', 'txn_recipient_'.uniqid(),
        );

        return (int) $ledgerEntry->id;
    }

    public function test_delivery_is_skipped_when_no_billing_contact_is_configured(): void
    {
        Notification::fake();
        $ledgerEntryId = $this->ledgerEntryIdForDisputeChargeback();

        app()->call([new SendChargebackDisputeNotification($ledgerEntryId), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_delivery_is_skipped_when_the_contact_has_opted_out(): void
    {
        Notification::fake();
        $ledgerEntryId = $this->ledgerEntryIdForDisputeChargeback();
        $businessId = (int) DB::table('business_usage_ledger_entries')->where('id', $ledgerEntryId)->value('business_id');
        $business = Business::find($businessId);
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', 'jane@example.test', false, (int) $business->customer_id);

        app()->call([new SendChargebackDisputeNotification($ledgerEntryId), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_delivery_is_skipped_when_the_resolved_email_is_blank(): void
    {
        Notification::fake();
        $ledgerEntryId = $this->ledgerEntryIdForDisputeChargeback();
        $businessId = (int) DB::table('business_usage_ledger_entries')->where('id', $ledgerEntryId)->value('business_id');
        $business = Business::find($businessId);
        DB::table('business_billing_contacts')->insert([
            'business_id' => $business->id, 'contact_user_id' => null, 'contact_name' => 'Jane Doe',
            'contact_email' => '', 'notification_opt_in' => true, 'updated_by_user_id' => (int) $business->customer_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->call([new SendChargebackDisputeNotification($ledgerEntryId), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_the_notification_content_states_the_exact_dispute_id_amount_currency_and_that_billing_is_suspended(): void
    {
        Notification::fake();
        $ledgerEntryId = $this->ledgerEntryIdForDisputeChargeback();
        $businessId = (int) DB::table('business_usage_ledger_entries')->where('id', $ledgerEntryId)->value('business_id');
        $business = Business::find($businessId);
        $expectedEmail = 'jane'.uniqid().'@example.test';
        app(BillingProfileManager::class)->updateBillingContact($business, null, 'Jane Doe', $expectedEmail, true, (int) $business->customer_id);

        app()->call([new SendChargebackDisputeNotification($ledgerEntryId), 'handle']);

        Notification::assertSentOnDemand(
            ChargebackDisputeNotification::class,
            function (ChargebackDisputeNotification $notification, array $channels, object $notifiable) use ($expectedEmail) {
                $mail = $notification->toMail($notifiable);
                $rendered = implode(' ', $mail->introLines);

                return $notifiable->routes['mail'] === $expectedEmail
                    && $notification->providerDisputeId === 'dp_fake_recipient'
                    && $notification->amountMicro === '1000000'
                    && $notification->currencyCode === 'USD'
                    && str_contains($rendered, 'dp_fake_recipient')
                    && str_contains($rendered, '1000000')
                    && str_contains($rendered, 'suspended');
            },
        );
    }
}
