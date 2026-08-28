<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Events\Usage\BusinessFundingAttemptSucceeded;
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
use App\Models\AppConfig;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessBillingReceiptRepository;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseRepository;
use App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageLimitTransitionRepository;
use App\Repositories\Contracts\BusinessUsageRateActivationRepository;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use App\Repositories\Contracts\UsageMeterTransitionRepository;
use App\Repositories\Eloquent\EloquentBusinessFundingAttemptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1 —
 * direct, single-process proofs of the shared, always-row-locked funding-
 * attempt finalizer's own guarantees: exactly one terminal transition,
 * exactly one BusinessFundingAttemptSucceeded dispatch, exactly one
 * SendReceiptNotification dispatch decision, crash-between-credit-and-
 * finalization recoverability, and the customer-facing controller
 * endpoint's own no-500 guarantee. Complements (not duplicates) the true
 * OS-level concurrency proof in ConcurrentTopUpConcurrencyTest.
 */
class FundingConfirmationConcurrencyCorrectionTest extends TestCase
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
     * @return array{0: \App\Models\Customer, 1: \App\Models\Business}
     */
    private function businessWithProviderCustomer(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        return [$customer, $business];
    }

    /**
     * Mirrors AddonPurchaseTransitionAuditTest's own identical helper.
     */
    private function businessWithProviderCustomerAndCatalogRow(): array
    {
        [$customer, $business] = $this->businessWithProviderCustomer();

        $currencyId = Currency::query()->first()->id;
        DB::table('business_usage_addon_catalog')->insert([
            'addon_key' => 'fixture-concurrency-addon', 'display_name' => 'Fixture Concurrency Add-on', 'price_micro' => 1_000_000,
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
            'https://fake.stripe.test/receipts/ch_fake_concurrency_verified',
            'ch_fake_concurrency_verified',
        ));
    }

    /**
     * Mirrors UsageBillingDashboardAuthorizationTest's own identical
     * helper — needed only by test 5's own HTTP-layer assertion.
     */
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
     * Mirrors UsageBillingDashboardAuthorizationTest's own identical
     * helper — needed only by test 5's own HTTP-layer assertion.
     */
    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 1 — the direct, single-process proof of guarantees 1, 3, 4, 5,
     * 6, using two plain sequential calls (no interleaving mock). Calls
     * the shared method directly — no repository mock is needed here.
     */
    public function test_a_true_duplicate_credit_race_between_two_confirmation_callers_produces_exactly_one_transition_event_and_receipt_dispatch(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);

        // Two separately-fetched instances — EloquentBusinessFundingAttemptRepository::update()'s
        // own fill()+save() mutates its input in place, so reusing one
        // instance for both calls would not reproduce a genuine race.
        $winner = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $loser = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        Event::fake([BusinessFundingAttemptSucceeded::class]);
        Queue::fake();

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $checkoutManager->confirmAttemptFromReturn($winner);
        $loserResult = $checkoutManager->confirmAttemptFromReturn($loser);

        $this->assertSame(FundingAttemptState::Succeeded, $loserResult->state);

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)
            ->count();
        $this->assertSame(1, $creditCount);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1);
        Queue::assertPushed(SendReceiptNotification::class, 1);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 2 — the deterministic proof of the exact interleaving the
     * focused review identified: caller A's own credit is inserted first;
     * before A reaches its own finalization, caller B's credit attempt
     * collides and B finalizes first; A then resumes and must safely
     * defer. Intercepts UsageWalletManager::creditFromFunding() — a call
     * every version of confirmSucceeded() has always made, sitting before
     * any finalization-level transaction opens. Under the exceptional
     * post-merge correction's own outer DB::transaction() around
     * confirmSucceeded(), caller A's own credit and caller B's own entire
     * confirmation both execute as nested savepoint writes within A's own
     * still-open outer transaction, on the same connection — visible to
     * each other in-transaction, not as independently committed
     * sequences. This proves the shared finalizer's own deterministic
     * ordering/idempotency behavior, not independent-transaction commit;
     * ConcurrentTopUpConcurrencyTest's own OS-process-level test is the
     * genuine independent-transaction proof.
     */
    public function test_the_credit_winner_safely_defers_when_the_recovery_caller_finalizes_first(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $winner = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($winner);
        $racedAttemptId = $winner->id;

        Event::fake([BusinessFundingAttemptSucceeded::class]);
        Queue::fake();

        // Container-resolution order, explicit: the real wallet manager is
        // resolved first, while the container's own default binding is
        // still in effect, giving a fully real, working instance used as
        // the delegate for every genuine credit call.
        $realWalletManager = app(UsageWalletManager::class);

        // Each of UsageWalletManager's own twelve constructor dependencies
        // is resolved individually via the container, in exactly the
        // order its own constructor declares them, so the mock below is
        // genuinely, validly constructed — not left with twelve null
        // properties, which is what happens when Mockery::mock() is given
        // no constructor-argument array at all.
        $mock = Mockery::mock(UsageWalletManager::class, [
            app(BusinessUsageWalletRepository::class),
            app(BusinessUsageRateRepository::class),
            app(BusinessUsageRateActivationRepository::class),
            app(UsageMeterRepository::class),
            app(UsageMeterTransitionRepository::class),
            app(BusinessUsageReservationRepository::class),
            app(BusinessUsageLedgerEntryRepository::class),
            app(BusinessFeatureUsageLimitRepository::class),
            app(PlatformFeatureUsageSafetyLimitRepository::class),
            app(BusinessUsageLimitTransitionRepository::class),
            app(BusinessUsageWalletBillingStatusTransitionRepository::class),
            app(BusinessBillingReceiptRepository::class),
        ])->makePartial();

        // Recursion guard: a single, closure-captured flag, set to true on
        // the stub's first invocation, before caller B is ever run.
        $hasIntercepted = false;

        $mock->shouldReceive('creditFromFunding')->andReturnUsing(function (...$args) use (&$hasIntercepted, $realWalletManager, $racedAttemptId) {
            if ($hasIntercepted) {
                // Caller B's own nested credit call, or any call after the
                // first — delegate directly to the real manager, no
                // further interleaving.
                $realWalletManager->creditFromFunding(...$args);

                return;
            }

            $hasIntercepted = true;

            // Caller A's own first credit call — inserted as a nested
            // savepoint write within A's own still-open outer transaction
            // (confirmSucceeded()'s own DB::transaction(), §2.1) — visible
            // in-transaction, on this same connection, though not yet
            // durably committed until A's own outer transaction commits.
            $realWalletManager->creditFromFunding(...$args);

            // Before returning control to A, run caller B's entire
            // confirmation to completion. B's own fresh findById() fetch
            // sees a still-ProviderPending in-memory model, since nothing
            // has touched attempt.state yet at this point in the sequence.
            $loser = app(BusinessFundingAttemptRepository::class)->findById($racedAttemptId);
            app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($loser);

            // Control now returns to A. A's own credit is already
            // inserted (visible in-transaction); confirmSucceeded() (for
            // A) proceeds to its own call to the shared finalizer, which
            // will now see the already-Succeeded row B just finalized
            // (itself a nested savepoint write, same connection) and
            // safely defer.
        });

        // Bound before anything resolves UsageBillingCheckoutManager, so
        // both caller A's own outer checkout manager and caller B's own
        // (resolved inside the interception closure above) are wired to
        // this same mock.
        $this->app->instance(UsageWalletManager::class, $mock);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $checkoutManager->confirmAttemptFromReturn($winner);

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $racedAttemptId)
            ->count();
        $this->assertSame(1, $creditCount);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $racedAttemptId)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1);
        Queue::assertPushed(SendReceiptNotification::class, 1);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($racedAttemptId);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 3 — the direct proof of guarantee 7. Simulates a crash between
     * financial credit and attempt-state finalization by crediting
     * directly, bypassing confirmSucceeded() entirely, leaving the
     * attempt's own persisted state at ProviderPending — then proves a
     * normal replay (a real customer retry, or a redelivered webhook,
     * would look identical) completes the one remaining transition
     * exactly once instead of leaving the attempt permanently stuck.
     *
     * Under the exceptional post-merge implementation correction's own
     * outer DB::transaction() around confirmSucceeded() (§2.1), this
     * exact partial state — credit durably committed, attempt durably
     * non-terminal — can no longer be produced by any real call through
     * confirmSucceeded() itself; it is reachable only through a direct,
     * bypassing call such as this fixture's own, modeling legacy or
     * explicitly seeded partial persistence (e.g. data left behind by a
     * predecessor design, or manually seeded test/ops data), not a crash
     * mid-request through the corrected production code path.
     *
     * Queue::fake() is deliberately used here, unlike item 6 below: this
     * test exercises recovery of that legacy/seeded partial state, not
     * receipt-execution timing. Without it, this fixture's own direct
     * credit call — itself a genuine, independent, top-level transaction,
     * since it bypasses confirmSucceeded() and its outer transaction
     * entirely — would durably commit and its own ->afterCommit()-queued
     * SendReceiptNotification would execute for real, synchronously,
     * under this suite's own sync queue, against the attempt this
     * fixture is deliberately leaving non-terminal — which is exactly
     * the scenario item 6 exists to prove no longer happens through the
     * real production path, not something this test's own artificial
     * setup step should be asserting one way or the other.
     */
    public function test_a_crash_between_credit_and_state_finalization_is_completed_exactly_once_on_replay(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        Queue::fake();

        app(UsageWalletManager::class)->creditFromFunding(
            (int) $attempt->business_id,
            UsageLedgerEntryType::PaidTopUp,
            (int) $attempt->expected_amount_micro,
            (int) $attempt->id,
            $attempt->local_idempotency_key.':credit',
        );

        $this->registerVerifiedCheckoutOutcome($attempt);

        Event::fake([BusinessFundingAttemptSucceeded::class]);

        $replayResult = app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($attempt);

        $this->assertSame(FundingAttemptState::Succeeded, $replayResult->state);

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)
            ->count();
        $this->assertSame(1, $creditCount);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1);

        // Proves the replay's own absorbed, colliding credit attempt did
        // not produce a second receipt dispatch — exactly one push,
        // registered by the fixture's own original (pre-replay) credit.
        Queue::assertPushed(SendReceiptNotification::class, 1);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state, 'The attempt must not remain permanently stuck.');
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 4 — the AddonPurchase-branch sibling of test 1, proving both
     * the purchase-level and the funding-attempt-level outcomes.
     */
    public function test_a_genuinely_simultaneous_double_confirmation_of_an_addon_purchase_produces_exactly_one_completion_transition(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomerAndCatalogRow();
        $result = app(UsageBillingCheckoutManager::class)->initiateAddonPurchase($business, 'fixture-concurrency-addon', $customer->user_id);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);

        $winner = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $loser = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);

        Event::fake([BusinessFundingAttemptSucceeded::class]);
        Queue::fake();

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $checkoutManager->confirmAttemptFromReturn($winner);
        $checkoutManager->confirmAttemptFromReturn($loser);

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)
            ->count();
        $this->assertSame(1, $creditCount);

        $transitions = app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($result->addonPurchaseId);
        $this->assertCount(1, $transitions);

        $purchase = app(BusinessUsageAddonPurchaseRepository::class)->findById($result->addonPurchaseId);
        $this->assertSame(AddonPurchaseStatus::Completed, $purchase->status);

        Queue::assertPushed(SendReceiptNotification::class, 1);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 5 — the end-to-end, HTTP-layer proof of guarantee 1
     * specifically, not merely a transitive inference from test 1. Uses
     * the same constructor-initialized Mockery partial-mock technique the
     * exceptional post-review correction established, but the
     * controller's own findById() call is what receives the stale loser,
     * and the HTTP request itself is the thing under test.
     */
    public function test_the_customer_return_controller_endpoint_returns_the_normal_success_redirect_when_the_attempt_was_already_confirmed_by_a_concurrent_caller(): void
    {
        $owner = $this->actingAsHttpCustomer();
        $workspace = $this->entitledWorkspace($owner->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($owner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $owner->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $owner->user_id);

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $owner->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $racedAttemptId = $attempt->id;

        $realRepository = app(BusinessFundingAttemptRepository::class);

        $mock = Mockery::mock(EloquentBusinessFundingAttemptRepository::class, [new BusinessFundingAttempt()])->makePartial();

        $mock->shouldReceive('findById')
            ->with($racedAttemptId)
            ->once()
            ->andReturnUsing(function () use ($realRepository, $racedAttemptId) {
                $winner = $realRepository->findById($racedAttemptId);
                $loser = $realRepository->findById($racedAttemptId);

                app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($winner);

                return $loser;
            });

        $mock->shouldReceive('findById')
            ->withAnyArgs()
            ->andReturnUsing(fn (int $id) => $realRepository->findById($id));

        $this->app->instance(BusinessFundingAttemptRepository::class, $mock);

        $this->get(route('customer.workspaces.businesses.usage-billing.top-up.confirm', [$workspace->uid, $business->uid, $racedAttemptId]))
            ->assertRedirect()
            ->assertSessionHas('flash_success');

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $racedAttemptId)
            ->count();
        $this->assertSame(1, $creditCount);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $racedAttemptId)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);
    }

    /**
     * RFC-005 Funding Confirmation Concurrency Correction Contract §5.1
     * item 6 — added by the exceptional post-merge implementation
     * correction; the direct regression proof for that correction
     * itself. Uses the real sync queue (.env.testing's own
     * QUEUE_CONNECTION=sync), deliberately without Queue::fake(), so
     * SendReceiptNotification's own ->afterCommit() dispatch genuinely
     * executes inline, synchronously, as part of this call. Proves it
     * only ever observes the attempt already durably Succeeded — the
     * exact precondition the pre-correction credit-first ordering,
     * without the outer transaction, could violate — never throwing the
     * RuntimeException that regression produced.
     */
    public function test_a_normally_confirmed_funding_attempt_dispatches_its_receipt_notification_only_after_the_attempt_is_durably_succeeded(): void
    {
        [$customer, $business] = $this->businessWithProviderCustomer();
        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->registerVerifiedCheckoutOutcome($attempt);

        // No Queue::fake() here, unlike every other test in this file —
        // the whole point is to let the real sync-queue job execute
        // inline. A single, uncontested, self-contained call: if the
        // outer transaction (§2.1) did not exist, SendReceiptNotification
        // would fire before finalizeFundingAttemptState() ever committed
        // and this call would throw a RuntimeException.
        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $checkoutManager->confirmAttemptFromReturn($attempt);

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(FundingAttemptState::Succeeded, $freshAttempt->state);

        $creditCount = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)
            ->count();
        $this->assertSame(1, $creditCount);

        $succeededTransitionCount = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->count();
        $this->assertSame(1, $succeededTransitionCount);

        // Proves SendReceiptNotification::handle() genuinely ran to
        // completion, synchronously, inline, and observed the attempt
        // already Succeeded — the same assertion shape
        // ReceiptBoundaryTest already establishes.
        $ledgerEntryId = DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $attempt->id)
            ->value('id');
        $receiptCount = DB::table('business_billing_receipts')
            ->where('ledger_entry_id', $ledgerEntryId)
            ->count();
        $this->assertSame(1, $receiptCount);
    }
}
