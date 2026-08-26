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
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §11 item 8/§25 item 91 — two independent
 * confirmation paths (synchronous retrieval + a later webhook) for the
 * same successful attempt produce exactly one PaidTopUp ledger entry,
 * never two.
 */
class FundingAttemptExactlyOnceWalletCreditTest extends TestCase
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
     * never requires_action via paymentIntentOutcomes, which no longer
     * applies since createOffSessionPaymentIntent() is never called for
     * this purpose), and requires no pre-saved instrument. The two
     * confirmation paths remain genuinely independent — the attempt
     * reaches provider_pending at creation, never succeeded, so the
     * synchronous confirmation and the later webhook are still two real,
     * separate confirmation attempts.
     */
    public function test_synchronous_confirmation_then_a_duplicate_webhook_credits_exactly_once(): void
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
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);

        $paymentMethodId = 'pm_fake_exactly_once';
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
            'pi_fake_exactly_once',
            $paymentMethodId,
        ));

        // Path 1: the synchronous browser-return confirmation.
        $checkoutManager->confirmAttemptFromReturn($attempt);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $attempt->state);

        // Path 2: a later, redundant webhook for the exact same attempt.
        $event = PaymentProviderEvent::query()->create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.uniqid(),
            'event_type' => 'checkout.session.completed',
            'provider_object_id' => $attempt->provider_session_or_intent_reference,
            'state' => 'processing',
            'attempts' => 1,
            'received_at' => now(),
        ]);
        $checkoutManager->confirmAttemptFromWebhook($attempt, $event);

        $ledgerCount = app(BusinessUsageLedgerEntryRepository::class)->query()
            ->where('funding_attempt_id', $attempt->id)->count();
        $this->assertSame(1, $ledgerCount, 'Exactly one ledger entry must exist for this funding attempt.');

        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);
    }
}
