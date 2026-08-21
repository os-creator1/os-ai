<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\SlotAgreementState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * M4 contract §23's forced-race scenarios (Correction Round 2 §E.2/§E.7).
 *
 * Deliberately does NOT use RefreshDatabase — the real cross-process tests
 * below need genuinely committed rows, which an open RefreshDatabase
 * transaction would hide entirely from the child processes, following
 * EntitlementManagerConcurrencyTest.php's own proven cross-process
 * pattern. Fixture rows are inserted directly (auto-committed) and
 * explicitly cleaned up in tearDown().
 *
 * The first two tests keep their own established sequential-double-
 * invocation proof (single-process, deterministic ordering) for the
 * stale-model/idempotent-replay guarantee — a real process boundary adds
 * nothing to that specific proof. The last two tests use genuinely
 * independent OS processes via tests/Feature/Usage/Support/
 * concurrent_slot_agreement_runner.php to prove the §E.2 result-application
 * dedup rule holds under real, not simulated, concurrency.
 */
class SlotAgreementConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__.'/Support/concurrent_slot_agreement_runner.php';

    private FakePaymentProviderGateway $gateway;

    private int $currencyId;

    private array $createdWorkspaceIds = [];

    private array $createdUserIds = [];

    private array $createdPaymentProviderEventIds = [];

    private ?array $originalCoreCatalogState = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCoreCatalogState = (array) DB::table('workspace_plan_catalog')->where('tier', 'core')->first();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        if ($this->originalCoreCatalogState !== null) {
            DB::table('workspace_plan_catalog')->where('tier', 'core')->update([
                'price' => $this->originalCoreCatalogState['price'],
                'currency_id' => $this->originalCoreCatalogState['currency_id'],
                'additional_business_slot_price_ratio' => $this->originalCoreCatalogState['additional_business_slot_price_ratio'],
            ]);
        }

        if ($this->createdWorkspaceIds !== []) {
            $agreementIds = DB::table('additional_business_slot_agreements')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id');

            DB::table('additional_business_slot_renewal_charge_transitions')
                ->whereIn('renewal_charge_id', DB::table('additional_business_slot_renewal_charges')->whereIn('agreement_id', $agreementIds)->pluck('id'))
                ->delete();
            DB::table('additional_business_slot_renewal_charges')->whereIn('agreement_id', $agreementIds)->delete();
            DB::table('additional_business_slot_agreement_transitions')->whereIn('agreement_id', $agreementIds)->delete();
            DB::table('additional_business_slot_agreements')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();

            // additional_business_slot_{agreement,renewal_charge}_transitions.provider_event_id
            // restrictOnDelete()s against payment_provider_events — both
            // transition tables are already cleared above, so this is now
            // safe.
            if ($this->createdPaymentProviderEventIds !== []) {
                DB::table('payment_provider_events')->whereIn('id', $this->createdPaymentProviderEventIds)->delete();
            }

            $providerCustomerIds = DB::table('payment_provider_customers')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id');
            DB::table('business_payment_instruments')->whereIn('provider_customer_id', $providerCustomerIds)->delete();
            DB::table('payment_provider_customers')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();

            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            // workspace_plan_catalog_pricing_changes.actor_user_id
            // restrictOnDelete()s against users — every fixture's own
            // updateCatalogPricing() call durably records its admin actor.
            DB::table('workspace_plan_catalog_pricing_changes')->whereIn('actor_user_id', $this->createdUserIds)->delete();

            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        DB::table('currencies')->where('id', $this->currencyId)->delete();

        parent::tearDown();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function createOwnerUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Fixture', 'last_name' => 'Owner',
            'email' => 'owner'.uniqid().'@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createAdminUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Fixture', 'last_name' => 'Admin',
            'email' => 'admin'.uniqid().'@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function checkoutPendingAgreement(): array
    {
        $owner = User::find($this->createOwnerUserId());
        $admin = User::find($this->createAdminUserId());
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $this->createdWorkspaceIds[] = $workspace->id;

        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);

        return [$workspace, $owner, $admin, app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id)];
    }

    public function test_synchronous_return_and_a_later_webhook_converge_on_a_single_allocation(): void
    {
        [$workspace, $owner, , $agreement] = $this->checkoutPendingAgreement();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_race', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_race'];

        $manager = app(UsageBillingCheckoutManager::class);

        // Synchronous browser return arrives first.
        $manager->confirmSlotAgreementFromReturn($agreement);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $agreement->state);

        // A later webhook delivery for the identical Session arrives —
        // must be a pure no-op, never a second allocation.
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $agreement->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $this->createdPaymentProviderEventIds[] = $event->id;
        $manager->confirmSlotAgreementFromWebhook($agreement, $event);

        $allocationTransitionCount = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'additional_business_slots_changed')
            ->count();
        $this->assertSame(1, $allocationTransitionCount);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);
    }

    public function test_reconciliation_and_administrator_allocation_never_double_allocate_a_stuck_agreement(): void
    {
        [$workspace, $owner, $admin, $agreement] = $this->checkoutPendingAgreement();
        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['state' => 'allocation_pending']);

        // Simulate two genuinely concurrent callers by loading two
        // independent, both-stale snapshots of the same allocation_pending
        // row *before* either call runs — exactly what two workers racing
        // to read the row would each see under a real concurrent arrival.
        // Correction Round 2 §A: performVerifiedAllocation() itself now
        // reloads/locks the persisted row before acting, ignoring the
        // caller-supplied model's own stale state, which is what actually
        // serializes this — not merely RFC-004's own idempotency-key
        // uniqueness underneath it.
        $reconciliationView = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $administratorView = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $manager = app(UsageBillingCheckoutManager::class);

        \Illuminate\Support\Facades\Event::fake([\App\Events\Usage\AdditionalBusinessSlotAgreementCompleted::class]);

        // Reconciliation-style call arrives first (no administrator actor).
        $manager->performVerifiedAllocation($reconciliationView);

        // The administrator's own manual-allocation attempt, from its own
        // independently-read (now stale) allocation_pending snapshot, must
        // be absorbed as a true idempotent no-op — never a second
        // transition, never a doubled slot count.
        $manager->allocateSlotAgreementAsAdministrator($administratorView, $admin->id, 'Reconciliation follow-up.');

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $refreshed->state);

        // M4 contract §21 (Correction Round 2 §E.5) — stale-model authority:
        // RFC-004 allocation, the M4 completed transition, and the
        // completed event each happen exactly once, never twice, even
        // though both callers held an independently-read stale
        // allocation_pending snapshot.
        $allocationTransitionCount = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'additional_business_slots_changed')
            ->count();
        $this->assertSame(1, $allocationTransitionCount);

        $completedTransitionCount = DB::table('additional_business_slot_agreement_transitions')
            ->where('agreement_id', $agreement->id)
            ->where('to_state', 'completed')
            ->count();
        $this->assertSame(1, $completedTransitionCount);

        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\Usage\AdditionalBusinessSlotAgreementCompleted::class, 1);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);
    }

    /**
     * M4 contract §23 (Correction Round 2 §A/§E.2/§E.7) — two genuinely
     * independent OS processes both call performVerifiedAllocation()
     * against the identical allocation_pending agreement. The holder
     * process locks the agreement row, holds it for a second, then
     * allocates inside that same transaction; the waiter — started only
     * once the holder confirms its lock is genuinely held — must therefore
     * actually block on the row lock rather than race on scheduler luck.
     * Both processes must exit successfully (Correction Round 2's own
     * idempotent-replay design, not a hard capacity limit), and the
     * persisted outcome must show exactly one allocation.
     */
    public function test_real_concurrent_allocation_never_double_allocates(): void
    {
        [$workspace, , , $agreement] = $this->checkoutPendingAgreement();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_real_race', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_real_race'];

        app(UsageBillingCheckoutManager::class)->confirmSlotAgreementFromReturn($agreement);
        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['state' => 'allocation_pending']);

        $lockSpec = "additional_business_slot_agreements:id:{$agreement->id}";
        $holder = new Process([$this->phpBinary(), self::RUNNER, 'hold-then', $lockSpec, '1', 'perform-verified-allocation', (string) $agreement->id]);
        $holder->start();

        $deadline = microtime(true) + 5.0;

        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertStringContainsString('LOCKED', $holder->getOutput(), 'Holder process never signaled lock acquisition: '.$holder->getErrorOutput());

        $waiter = new Process([$this->phpBinary(), self::RUNNER, 'perform-verified-allocation', (string) $agreement->id]);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder process must succeed: '.$holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter process must succeed (idempotent replay), not error: '.$waiter->getErrorOutput());

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $refreshed->state);

        $allocationTransitionCount = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'additional_business_slots_changed')
            ->count();
        $this->assertSame(1, $allocationTransitionCount, 'Exactly one real allocation transition, never two.');

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);
    }

    /**
     * M4 contract §23 (Correction Round 2 §E.2/§E.7) — two genuinely
     * independent OS processes both retry the identical Failed renewal
     * charge, both configured to receive the same Stripe-idempotent
     * declined result, forced onto the *identical* attempted ordinal by a
     * deterministic cross-process barrier inside the runner
     * (BarrierGatedPaymentProviderGateway) rather than left to scheduler
     * luck: each process independently completes Phase A (lock, read,
     * validate, compute the attempted ordinal, commit, release the row
     * lock) and then blocks at the barrier — recording its own computed
     * idempotency key — until both have arrived, proving both genuinely
     * reached the identical ordinal before either is released to receive
     * its provider result and apply it. Never hold-then wrapping the
     * entire retry call here — that primitive holds its lock through the
     * delegate's *entire* call, forcing strict before/after sequencing
     * where each process would legitimately compute a *different* ordinal
     * instead of racing the same one.
     */
    public function test_real_concurrent_retry_produces_exactly_one_failed_transition(): void
    {
        [$workspace, $owner, , $agreement] = $this->checkoutPendingAgreement();
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_retry_race', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_retry_race'];
        app(UsageBillingCheckoutManager::class)->confirmSlotAgreementFromReturn($agreement);

        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['next_renewal_at' => now()->subMinute()]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';
        $result = app(UsageBillingCheckoutManager::class)->createScheduledRenewalCharge($agreement);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result->renewalChargeId);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.payment_failed',
            'provider_object_id' => $charge->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $this->createdPaymentProviderEventIds[] = $event->id;
        app(UsageBillingCheckoutManager::class)->markSlotRenewalChargeFailedFromWebhook($charge, 'generic_decline', $event);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);

        $barrierFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m4_retry_barrier_'.uniqid('', true).'.txt';

        try {
            $p1 = new Process([$this->phpBinary(), self::RUNNER, 'retry-slot-renewal-owner', (string) $charge->id, (string) $owner->id, 'declined', $barrierFile, '2', '10']);
            $p2 = new Process([$this->phpBinary(), self::RUNNER, 'retry-slot-renewal-owner', (string) $charge->id, (string) $owner->id, 'declined', $barrierFile, '2', '10']);
            $p1->start();
            $p2->start();
            $p1->wait();
            $p2->wait();

            $this->assertTrue($p1->isSuccessful(), 'P1 must succeed: '.$p1->getErrorOutput());
            $this->assertTrue($p2->isSuccessful(), 'P2 must succeed (deduplicated, not error): '.$p2->getErrorOutput());

            $this->assertFileExists($barrierFile, 'Neither process reached the provider-call barrier.');
            $recordedKeys = array_values(array_filter(explode("\n", trim(file_get_contents($barrierFile)))));
            $this->assertCount(2, $recordedKeys, 'Both processes must have reached the provider-call barrier before either received its result.');
            $this->assertSame($recordedKeys[0], $recordedKeys[1], 'Both processes must have computed the identical attempted-ordinal idempotency key.');

            $expectedOrdinal2Key = hash('sha256', $charge->local_idempotency_key.':attempt:2');
            $this->assertSame($expectedOrdinal2Key, $recordedKeys[0], 'Both processes must have computed ordinal 2 — the genuine next attempt after the webhook\'s own ordinal 1.');

            $failedTransitionCount = DB::table('additional_business_slot_renewal_charge_transitions')
                ->where('renewal_charge_id', $charge->id)
                ->where('to_state', 'failed')
                ->count();
            $this->assertSame(2, $failedTransitionCount, 'Ordinal 1 (webhook) + exactly one genuine ordinal-2 failure from the forced same-ordinal race — never a duplicate, never an ordinal 3.');
        } finally {
            @unlink($barrierFile);
        }
    }
}
