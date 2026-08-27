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
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Exceptions\Usage\ReceiptEvidenceUnavailableException;
use App\Jobs\Usage\SendReceiptNotification;
use App\Models\PaymentProviderEvent;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §11 item 11/§25 item 92 — a browser return alone,
 * with no webhook and no successful synchronous retrieval, never credits
 * the wallet; the state renders honestly as pending, never a fabricated
 * success.
 */
class RedirectBeforeWebhookConfirmationTest extends TestCase
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
     * RFC-005 Funding Provider-Flow Correction Contract §17 — corrected:
     * a ManualTopUp attempt is Checkout-Session-backed (provider_pending,
     * never requires_action, and requires no pre-saved instrument). The
     * "bare redirect never credits the wallet" invariant is fully
     * preserved — a browser being sent to the hosted Checkout page, with
     * no confirmAttemptFromReturn()/webhook call following, must never
     * itself credit anything.
     */
    public function test_a_bare_redirect_return_with_no_confirmation_call_never_credits_the_wallet(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        // Simulates the customer being sent to the hosted Checkout page —
        // no confirmAttemptFromReturn()/webhook call follows.
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);
        $this->assertNotSame(FundingAttemptState::Succeeded, $attempt->state);

        $ledgerCount = app(BusinessUsageLedgerEntryRepository::class)->query()
            ->where('funding_attempt_id', $attempt->id)->count();
        $this->assertSame(0, $ledgerCount, 'A bare redirect must never itself insert a ledger entry.');

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('0', (string) $wallet->available_balance_micro);
    }

    /**
     * Receipt Boundary Correction Contract §1 item 10/§7 step 5 — a
     * genuinely absent receiptUrl/receiptChargeId makes
     * ensureFundingReceipt() return null, so the queued job (running
     * inline under the sync queue) throws
     * ReceiptEvidenceUnavailableException — but the accounting
     * transaction has already committed by that point and is never
     * touched by this failure.
     */
    public function test_missing_receipt_evidence_fails_the_notification_job_without_reversing_accounting(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_missing_evidence';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($attempt),
            $checkoutManager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_missing_evidence',
            $paymentMethodId,
            null,
            null,
        ));

        try {
            $checkoutManager->confirmAttemptFromReturn($attempt);
            $this->fail('Expected ReceiptEvidenceUnavailableException to propagate from the sync-queued job.');
        } catch (ReceiptEvidenceUnavailableException $exception) {
            // Expected — the job fails clearly; accounting is unaffected.
        }

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);

        $receiptCount = app(\App\Repositories\Contracts\BusinessBillingReceiptRepository::class)->query()
            ->where('business_id', $business->id)->count();
        $this->assertSame(0, $receiptCount);
    }

    /**
     * Receipt Boundary Correction Contract §7 — application dispatch
     * idempotency: a duplicate return-then-webhook confirmation for an
     * already-Succeeded attempt never re-credits, so it never re-dispatches
     * a second SendReceiptNotification.
     */
    public function test_duplicate_return_and_webhook_confirmation_dispatches_the_receipt_job_exactly_once(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_duplicate_dispatch';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($attempt),
            $checkoutManager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_duplicate_dispatch',
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_duplicate_dispatch',
            'ch_fake_duplicate_dispatch',
        ));

        Queue::fake();

        $checkoutManager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $attempt->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $checkoutManager->confirmAttemptFromWebhook($attempt, $event);

        Queue::assertPushed(SendReceiptNotification::class, 1);
    }
}
