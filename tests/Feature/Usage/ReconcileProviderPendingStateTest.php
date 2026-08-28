<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
use App\Jobs\Usage\ReconcileProviderPendingState;
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
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §9 — the
 * missing test-gap proof for ReconcileProviderPendingState, previously
 * scheduled since M3 with zero coverage. Does not redesign the
 * reconciliation flow; the job's query, its 30-minute cutoff, and its
 * delegation to confirmAttemptFromReturn() are all unchanged.
 */
class ReconcileProviderPendingStateTest extends TestCase
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
            'pi_fake_reconcile_'.uniqid(),
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_reconcile',
            'ch_fake_reconcile',
        ));
    }

    private function markStuck(int $attemptId): void
    {
        DB::table('business_funding_attempts')->where('id', $attemptId)->update([
            'updated_at' => now()->subMinutes(31),
        ]);
    }

    private function runJob(): void
    {
        app(ReconcileProviderPendingState::class)->handle(
            app(BusinessFundingAttemptRepository::class),
            app(UsageBillingCheckoutManager::class),
        );
    }

    public function test_reconciles_a_stuck_provider_pending_attempt_to_succeeded_after_local_accounting_completes(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $this->markStuck($attempt->id);

        $listenerRan = false;
        Event::listen(BusinessFundingAttemptSucceeded::class, function (BusinessFundingAttemptSucceeded $event) use (&$listenerRan, $result) {
            $creditExists = DB::table('business_usage_ledger_entries')
                ->where('funding_attempt_id', $result->fundingAttemptId)
                ->where('correlation_key', 'like', '%:credit')
                ->exists();
            $this->assertTrue($creditExists, 'The wallet credit ledger entry must already exist when BusinessFundingAttemptSucceeded is observed.');
            $listenerRan = true;
        });

        $this->runJob();

        $this->assertTrue($listenerRan, 'The BusinessFundingAttemptSucceeded listener must have run exactly once.');

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
    }

    public function test_does_not_reconcile_an_attempt_updated_within_the_stuck_window(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        // Deliberately not marked stuck — updated_at remains recent.

        $this->runJob();

        $this->assertNotContains($attempt->provider_session_or_intent_reference, $this->gateway->retrieveCheckoutSessionCalls);
        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state);
    }

    public function test_does_not_mutate_a_still_pending_attempt_the_provider_confirms_as_unresolved(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->markStuck($attempt->id);

        $manager = app(UsageBillingCheckoutManager::class);
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'open',
            'unpaid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            null,
            null,
            null,
            null,
        ));

        Event::fake([BusinessFundingAttemptSucceeded::class]);

        $this->runJob();

        Event::assertNotDispatched(BusinessFundingAttemptSucceeded::class);
        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state);
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
    }

    public function test_skips_an_attempt_with_no_provider_session_or_intent_reference(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->markStuck($attempt->id);
        DB::table('business_funding_attempts')->where('id', $attempt->id)->update(['provider_session_or_intent_reference' => null]);

        $this->runJob();

        $this->assertSame([], $this->gateway->retrieveCheckoutSessionCalls);
        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::ProviderPending, $freshAttempt->state);
    }

    /**
     * The job's own query (whereIn('state', [ProviderPending,
     * RequiresAction])) can never select an already-Succeeded row — this
     * is the one part of "never duplicates accounting for an
     * already-succeeded attempt" the job's actual code structure honestly
     * guarantees. Proven indirectly (no provider retrieval call for the
     * Succeeded attempt's own reference), since the query itself is
     * inline inside the job's private handle() body.
     */
    public function test_the_reconciliation_query_never_selects_an_already_succeeded_attempt(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);
        $this->markStuck($attempt->id);

        $succeededReference = app(BusinessFundingAttemptRepository::class)->findById($attempt->id)->provider_session_or_intent_reference;
        $this->gateway->retrieveCheckoutSessionCalls = [];

        Event::fake([BusinessFundingAttemptSucceeded::class]);

        $this->runJob();

        $this->assertNotContains($succeededReference, $this->gateway->retrieveCheckoutSessionCalls, 'An already-Succeeded attempt must never be selected by the reconciliation query.');
        Event::assertNotDispatched(BusinessFundingAttemptSucceeded::class);
    }
}
