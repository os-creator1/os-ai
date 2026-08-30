<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Jobs\Usage\ReconcileProviderPendingState;
use App\Jobs\Usage\SendReceiptNotification;
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
use App\Repositories\Eloquent\EloquentBusinessFundingAttemptRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
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
            'https://fake.stripe.test/receipts/ch_fake_reconcile_'.$attempt->id,
            'ch_fake_reconcile_'.$attempt->id,
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

    /**
     * RFC-005 Reconciliation-Race Correction Contract §5 test 1 — proves
     * only the Tier 1 (§1) eligibility-recheck skip, not the try/catch.
     * $attemptA is the job's own collection's first member; while its own
     * BusinessFundingAttemptSucceeded dispatch is still synchronously in
     * flight, a real listener directly re-fetches and confirms $attemptB
     * — the collection's second, not-yet-processed member — simulating a
     * concurrent webhook resolving it before the job's own loop reaches
     * it. $attemptC is never touched by the race and reconciles normally.
     */
    public function test_a_stale_collection_member_resolved_before_its_turn_is_skipped_via_the_fresh_eligibility_recheck(): void
    {
        [$customerA, $businessA] = $this->businessWithProviderCustomer();
        $resultA = app(UsageBillingCheckoutManager::class)->initiateTopUp($businessA, $customerA->user_id, 5_000_000);
        $attemptA = app(BusinessFundingAttemptRepository::class)->findById($resultA->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attemptA);
        $this->markStuck($attemptA->id);

        [$customerB, $businessB] = $this->businessWithProviderCustomer();
        $resultB = app(UsageBillingCheckoutManager::class)->initiateTopUp($businessB, $customerB->user_id, 5_000_000);
        $attemptB = app(BusinessFundingAttemptRepository::class)->findById($resultB->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attemptB);
        $this->markStuck($attemptB->id);

        [$customerC, $businessC] = $this->businessWithProviderCustomer();
        $resultC = app(UsageBillingCheckoutManager::class)->initiateTopUp($businessC, $customerC->user_id, 5_000_000);
        $attemptC = app(BusinessFundingAttemptRepository::class)->findById($resultC->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attemptC);
        $this->markStuck($attemptC->id);

        $triggered = false;
        Event::listen(BusinessFundingAttemptSucceeded::class, function (BusinessFundingAttemptSucceeded $event) use (&$triggered, $attemptB) {
            if ($triggered) {
                return;
            }

            $triggered = true;

            $freshB = app(BusinessFundingAttemptRepository::class)->findById($attemptB->id);
            app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($freshB);
        });

        $this->runJob();

        $this->assertTrue($triggered, 'The simulated concurrent-webhook listener must have run exactly once.');

        foreach ([$attemptA, $attemptB, $attemptC] as $attempt) {
            $fresh = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
            $this->assertSame(FundingAttemptState::Succeeded, $fresh->state, "Attempt {$attempt->id} must be Succeeded.");

            $creditCount = DB::table('business_usage_ledger_entries')
                ->where('correlation_key', $attempt->local_idempotency_key.':credit')
                ->count();
            $this->assertSame(1, $creditCount, "Attempt {$attempt->id} must have exactly one credit ledger entry.");

            $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
                ->where('funding_attempt_id', $attempt->id)
                ->where('to_state', FundingAttemptState::Succeeded->value)
                ->count();
            $this->assertSame(1, $succeededTransitionCount, "Attempt {$attempt->id} must have exactly one succeeded transition row — confirmSucceeded() must not have been re-entered for it.");
        }
    }

    /**
     * RFC-005 Reconciliation-Race Correction Contract §5 test 2 — drives
     * the Tier 2 (§1) residual collision through confirmSucceeded()
     * itself, inside ReconcileProviderPendingState::handle()'s own loop,
     * so it is genuinely handle()'s own `catch
     * (UniqueConstraintViolationException) { continue; }` that catches
     * it — not a test-owned try/catch. The BusinessFundingAttemptRepository
     * contract is replaced with a constructor-initialized Mockery partial
     * mock (exceptional post-review correction): findById() is stubbed
     * once for the raced attempt's own id to perform the interleaving
     * (fetch two independent pre-race snapshots, confirm the winner for
     * real, return the still-stale loser to the job), and for every other
     * id to explicitly delegate to a separately-held, normally-constructed
     * real repository — so the later, unrelated attempt's own re-fetch
     * still reaches genuine persisted state.
     *
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5 —
     * the Tier 2 residual collision this test drives previously produced
     * two succeeded transition rows for the raced attempt, disclosed as
     * an accepted residual side effect. That defect is now closed at its
     * shared root: confirmSucceeded() routes every caller — winner and
     * loser alike — through one shared, always-row-locked finalizer, so
     * the loser observes the row already `Succeeded` under lock and
     * performs no transition at all. Exactly one succeeded transition row
     * now exists for the raced attempt.
     */
    public function test_a_true_duplicate_credit_race_is_caught_by_the_jobs_own_exception_boundary_and_reconciliation_continues_to_later_attempts(): void
    {
        [$racedCustomer, $racedBusiness] = $this->businessWithProviderCustomer();
        $racedResult = app(UsageBillingCheckoutManager::class)->initiateTopUp($racedBusiness, $racedCustomer->user_id, 5_000_000);
        $racedAttempt = app(BusinessFundingAttemptRepository::class)->findById($racedResult->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($racedAttempt);
        $this->markStuck($racedAttempt->id);
        $racedAttemptId = $racedAttempt->id;

        [$laterCustomer, $laterBusiness] = $this->businessWithProviderCustomer();
        $laterResult = app(UsageBillingCheckoutManager::class)->initiateTopUp($laterBusiness, $laterCustomer->user_id, 5_000_000);
        $laterAttempt = app(BusinessFundingAttemptRepository::class)->findById($laterResult->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($laterAttempt);
        $this->markStuck($laterAttempt->id);

        Event::fake([BusinessFundingAttemptSucceeded::class]);
        Queue::fake();

        $realRepository = app(BusinessFundingAttemptRepository::class);

        $mock = Mockery::mock(EloquentBusinessFundingAttemptRepository::class, [new BusinessFundingAttempt()])->makePartial();

        $mock->shouldReceive('findById')
            ->with($racedAttemptId)
            ->once()
            ->andReturnUsing(function () use ($realRepository, $racedAttemptId) {
                $winner = $realRepository->findById($racedAttemptId);
                $loser = $realRepository->findById($racedAttemptId);

                // The real confirmation path, run to completion here —
                // this is what makes the loser's own later confirmation,
                // driven by the job's own code below, a genuine duplicate.
                app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($winner);

                return $loser;
            });

        $mock->shouldReceive('findById')
            ->withAnyArgs()
            ->andReturnUsing(fn (int $id) => $realRepository->findById($id));

        $this->app->instance(BusinessFundingAttemptRepository::class, $mock);

        // The container swap above happens before this call — $this->runJob()
        // resolves BusinessFundingAttemptRepository::class itself and passes
        // it into handle(), so it receives the mock configured above.
        $this->runJob();

        $freshRaced = app(BusinessFundingAttemptRepository::class)->findById($racedAttemptId);
        $this->assertSame(FundingAttemptState::Succeeded, $freshRaced->state, 'The raced attempt must still end up Succeeded (the winner\'s own confirmation).');

        $freshLater = app(BusinessFundingAttemptRepository::class)->findById($laterAttempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshLater->state, 'The later, unrelated attempt must still be reconciled in the same run — the job\'s own catch must preserve continuation.');

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('correlation_key', $racedAttempt->local_idempotency_key.':credit')
            ->count();
        $this->assertSame(1, $creditCount, 'Exactly one credit ledger entry must exist for the raced attempt — the ledger correlation_key unique constraint prevents a second.');

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $racedAttemptId)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount, 'Exactly one succeeded transition row must exist for the raced attempt — the Funding Confirmation Concurrency Correction closes the residual duplicate-transition defect PR #143 disclosed; the job\'s own re-fetch skip and confirmSucceeded()\'s own shared, always-locked finalizer now jointly guarantee this.');

        $succeededEventCount = Event::dispatched(BusinessFundingAttemptSucceeded::class, fn (BusinessFundingAttemptSucceeded $event) => $event->fundingAttemptId === $racedAttemptId)->count();
        $this->assertSame(1, $succeededEventCount, 'Exactly one BusinessFundingAttemptSucceeded dispatch must exist for the raced attempt — the loser\'s own execution never reaches that dispatch site.');

        $receiptDispatchCount = Queue::pushed(SendReceiptNotification::class, function (SendReceiptNotification $job) use ($racedAttemptId) {
            return (new \ReflectionProperty($job, 'fundingAttemptId'))->getValue($job) === $racedAttemptId;
        })->count();
        $this->assertSame(1, $receiptDispatchCount, 'Exactly one SendReceiptNotification dispatch decision must exist for the raced attempt — only the winner\'s.');
    }

    /**
     * RFC-005 Reconciliation-Race Correction Contract §5 test 3 —
     * unchanged design (guarantee 5): the job's new catch is scoped to
     * exactly Illuminate\Database\UniqueConstraintViolationException, so a
     * genuinely unrelated exception (here, UsageWalletNotFoundException,
     * forced by deleting the business's own wallet row before
     * confirmation) still propagates out of handle() uncaught.
     */
    public function test_a_genuinely_unrelated_exception_is_not_caught_and_still_propagates(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $this->markStuck($attempt->id);

        // The funding attempt's own row still references this wallet via a
        // composite (wallet_id, business_id) foreign key, so a plain delete
        // is rejected (1451) — the row is deliberately orphaned here rather
        // than also deleting the attempt, since the test needs the job's
        // own query to still select it and reach the unrelated exception.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_usage_wallets')->where('business_id', $attempt->business_id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->expectException(UsageWalletNotFoundException::class);

        $this->runJob();
    }
}
