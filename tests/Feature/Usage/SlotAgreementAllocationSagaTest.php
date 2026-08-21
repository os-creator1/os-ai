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
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementTransitionRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M4 contract §8's full payment_succeeded -> allocation_pending ->
 * completed/allocation_failed saga, against the real, merged
 * EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment().
 *
 * Strengthened per Correction Round 1 §F: performVerifiedAllocation() and
 * performVerifiedRenewalChargeAllocation() both converge on the identical
 * identity-provenance rule; scheduled_renewal success never allocates; a
 * paid mid_period_increase charge allocates exactly its own frozen
 * allocation_delta; two paid increases use distinct payment-allocation
 * idempotency keys; a crash/replay after Succeeded re-runs allocation
 * idempotently; current_allocation_count synchronizes from RFC-004's own
 * returned assignment, never incremented locally.
 */
class SlotAgreementAllocationSagaTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProviderGateway $gateway;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    /**
     * @return array{0: Workspace, 1: User, 2: User}
     */
    private function entitledWorkspace(int $initialAdditionalSlots = 0): array
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, $initialAdditionalSlots);

        return [$workspace->fresh(), $owner, $admin];
    }

    /**
     * Drives the full quote -> checkout -> confirm flow, registering a
     * fake PaymentMethod against the Workspace's own freshly created
     * provider customer, and returns the completed agreement.
     */
    private function completeInitialCheckout(Workspace $workspace, User $owner, int $targetAllocationCount): \App\Models\AdditionalBusinessSlotAgreement
    {
        $manager = app(UsageBillingCheckoutManager::class);

        $quote = $manager->quoteAdditionalSlotAgreement($workspace, $targetAllocationCount, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);

        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        // Provider payment-method ids are globally unique in reality (and
        // in the schema), so a per-workspace value is required — reusing
        // one literal string across independent workspaces in the same
        // test would collide against an earlier workspace's own row.
        $providerPaymentMethodId = 'pm_fake_test_'.$workspace->id;
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $providerPaymentMethodId, $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => $providerPaymentMethodId];

        $manager->confirmSlotAgreementFromReturn($agreement);

        return app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);
    }

    public function test_initial_checkout_saga_completes_and_allocates_exactly_the_paid_delta(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);

        $agreement = $this->completeInitialCheckout($workspace, $owner, 2);

        $this->assertSame(SlotAgreementState::Completed, $agreement->state);
        $this->assertSame(2, $agreement->current_allocation_count);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);

        $transitions = app(AdditionalBusinessSlotAgreementTransitionRepository::class)
            ->forAgreement((int) $agreement->id)->pluck('to_state')->map(fn ($s) => $s->value)->all();
        $this->assertSame(['quote_created', 'checkout_pending', 'payment_succeeded', 'allocation_pending', 'completed'], $transitions);
    }

    public function test_identity_provenance_is_identical_across_ordinary_reconciliation_and_administrator_callers(): void
    {
        [$workspace, $owner, $admin] = $this->entitledWorkspace(0);

        // Ordinary payment-triggered path.
        $agreement = $this->completeInitialCheckout($workspace, $owner, 2);

        $ordinaryEntitlementTransition = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)->orderByDesc('id')->first();
        $this->assertNull($ordinaryEntitlementTransition->actor_user_id);
        $this->assertSame($owner->id, $ordinaryEntitlementTransition->requesting_customer_user_id);

        // Reconciliation-style direct call (mirrors ReconcileSlotAgreementAllocation).
        [$workspace2, $owner2] = $this->entitledWorkspace(0);
        $manager = app(UsageBillingCheckoutManager::class);
        $quote2 = $manager->quoteAdditionalSlotAgreement($workspace2, 2, $owner2->id);
        $agreement2 = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote2->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement2, $owner2->id);
        $agreement2 = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement2->id);
        $providerCustomer2 = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace2->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_test2', $providerCustomer2->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement2->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_test2'];
        // Simulate a webhook-first confirmation reaching payment_succeeded,
        // then a reconciliation-style direct call to performVerifiedAllocation().
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'checkout.session.completed',
            'provider_object_id' => $agreement2->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $manager->confirmSlotAgreementFromWebhook($agreement2, $event);

        $reconciliationTransition = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace2->id)->orderByDesc('id')->first();
        $this->assertNull($reconciliationTransition->actor_user_id);
        $this->assertSame($owner2->id, $reconciliationTransition->requesting_customer_user_id);

        // Administrator manual-allocation action — an agreement whose
        // payment is already verified (payment_succeeded) but whose
        // allocation was never yet attempted, exactly the manual-recovery
        // scenario this action exists for.
        [$workspace3, $owner3, $admin3] = $this->entitledWorkspace(0);
        $quote3 = $manager->quoteAdditionalSlotAgreement($workspace3, 2, $owner3->id);
        $agreement3 = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote3->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement3, $owner3->id);
        $agreement3 = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement3->id);
        \Illuminate\Support\Facades\DB::table('additional_business_slot_agreements')->where('id', $agreement3->id)->update(['state' => 'payment_succeeded']);
        $agreement3 = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement3->id);
        $manager->allocateSlotAgreementAsAdministrator($agreement3, $admin3->id, 'Manual recovery.');

        $adminTransition = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace3->id)->orderByDesc('id')->first();
        $this->assertNull($adminTransition->actor_user_id);
        $this->assertSame($owner3->id, $adminTransition->requesting_customer_user_id);

        $agreementTransition = app(AdditionalBusinessSlotAgreementTransitionRepository::class)
            ->forAgreement((int) $agreement3->id)->last();
        $this->assertSame($admin3->id, $agreementTransition->actor_user_id);
    }

    public function test_scheduled_renewal_success_never_calls_rfc004_allocation(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);
        $agreement = $this->completeInitialCheckout($workspace, $owner, 2);

        $assignmentBefore = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);

        \Illuminate\Support\Facades\DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update([
            'next_renewal_at' => now()->subMinute(),
        ]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_renewal', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));

        app(UsageBillingCheckoutManager::class)->createScheduledRenewalCharge($agreement);

        $assignmentAfter = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame($assignmentBefore->additional_business_slots, $assignmentAfter->additional_business_slots);

        $allocationTransitionCountBefore = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'additional_business_slots_changed')
            ->count();
        $this->assertSame(1, $allocationTransitionCountBefore); // only the initial-checkout allocation transition exists
    }

    public function test_paid_mid_period_increase_allocates_exactly_its_own_frozen_delta_and_synchronizes_current_count(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);
        // Core's own [0,1,2] additional-slot cap (RFC-004) leaves room for
        // exactly one more slot above the 1 already allocated.
        $agreement = $this->completeInitialCheckout($workspace, $owner, 1);

        $manager = app(UsageBillingCheckoutManager::class);
        $changeOperationId = (string) \Illuminate\Support\Str::uuid();
        $result = $manager->requestSlotAgreementIncrease($agreement, 2, $changeOperationId, $owner->id);

        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Succeeded, $result->state);

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(2, $refreshed->current_allocation_count);
        $this->assertSame(2, $refreshed->target_allocation_count);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);

        $charge = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result->renewalChargeId);
        $this->assertSame(1, $charge->allocation_delta);
    }

    /**
     * Core's own [0,1,2] additional-slot cap (RFC-004) means a single
     * workspace can never accumulate two separate paid increases — the
     * claim under test (increase charges never reuse a payment-allocation
     * idempotency key) is instead proven across two independent
     * workspaces, each driving its own single successful paid increase.
     */
    public function test_two_paid_increase_charges_use_distinct_payment_allocation_idempotency_keys(): void
    {
        $manager = app(UsageBillingCheckoutManager::class);

        [$workspaceA, $ownerA] = $this->entitledWorkspace(0);
        $agreementA = $this->completeInitialCheckout($workspaceA, $ownerA, 1);
        $result1 = $manager->requestSlotAgreementIncrease($agreementA, 2, (string) \Illuminate\Support\Str::uuid(), $ownerA->id);

        [$workspaceB, $ownerB] = $this->entitledWorkspace(0);
        $agreementB = $this->completeInitialCheckout($workspaceB, $ownerB, 1);
        $result2 = $manager->requestSlotAgreementIncrease($agreementB, 2, (string) \Illuminate\Support\Str::uuid(), $ownerB->id);

        $charge1 = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result1->renewalChargeId);
        $charge2 = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result2->renewalChargeId);

        $this->assertNotSame($charge1->local_idempotency_key, $charge2->local_idempotency_key);

        $keys = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->whereIn('workspace_id', [$workspaceA->id, $workspaceB->id])
            ->where('transition_type', 'additional_business_slots_changed')
            ->pluck('payment_idempotency_key')->all();
        $this->assertCount(4, $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    /**
     * M4 contract §8 (Correction Round 2 §A) — an RFC-004 allocation
     * exception (here, ComplimentaryWorkspaceCannotAllocatePaidSlotsException,
     * a real RFC-004 rejection, never a manufactured one) must durably
     * commit allocation_failed *before* the exception is rethrown — the
     * caller still observes the exception, but the failure state survives
     * it, queryable on a fresh read. Then proves allocation_failed is a
     * genuinely recoverable entry state: once the underlying condition is
     * resolved (the workspace's own complimentary flag is revoked), a
     * plain reconciliation-style direct call to performVerifiedAllocation()
     * — never a fresh charge, never a new idempotency key — completes it.
     */
    public function test_allocation_failed_is_durably_committed_before_rethrow_and_recoverable_via_reconciliation(): void
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace->fresh(), 1, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $providerPaymentMethodId = 'pm_fake_comp_'.$workspace->id;
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($providerPaymentMethodId, $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => $providerPaymentMethodId];

        try {
            $manager->confirmSlotAgreementFromReturn($agreement);
            $this->fail('Expected the complimentary-workspace RFC-004 rejection to propagate.');
        } catch (\App\Exceptions\Entitlement\ComplimentaryWorkspaceCannotAllocatePaidSlotsException $e) {
            // Expected — the exception must still reach the caller.
        }

        $failed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::AllocationFailed, $failed->state);

        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace->fresh(), $admin->id);

        $assignment = $manager->performVerifiedAllocation($failed);

        $recovered = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $recovered->state);
        $this->assertSame($assignment->additional_business_slots, $recovered->current_allocation_count);
    }

    /**
     * M4 contract §8/§14 (Correction Round 2 §A) — the same recovery, via
     * allocateSlotAgreementAsAdministrator() instead of the reconciliation-
     * style direct call, proving both authorized recovery paths accept
     * allocation_failed.
     */
    public function test_allocation_failed_is_recoverable_via_administrator_action(): void
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace->fresh(), 1, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $providerPaymentMethodId = 'pm_fake_comp2_'.$workspace->id;
        $this->gateway->registerPaymentMethod(new PaymentMethodResult($providerPaymentMethodId, $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => $providerPaymentMethodId];

        try {
            $manager->confirmSlotAgreementFromReturn($agreement);
            $this->fail('Expected the complimentary-workspace RFC-004 rejection to propagate.');
        } catch (\App\Exceptions\Entitlement\ComplimentaryWorkspaceCannotAllocatePaidSlotsException $e) {
        }

        $failed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::AllocationFailed, $failed->state);

        app(EntitlementManager::class)->revokeComplimentaryStatus($workspace->fresh(), $admin->id);

        $manager->allocateSlotAgreementAsAdministrator($failed, $admin->id, 'Manual recovery after complimentary revoke.');

        $recovered = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $recovered->state);
    }

    /**
     * M4 contract §22 (Correction Round 2 §A) — findRequiringAllocationRecovery()
     * discovers a payment_succeeded agreement stuck past the crash window
     * (checkout verified, process died before performVerifiedAllocation()
     * ever ran), not merely allocation_pending.
     */
    public function test_reconciliation_discovers_a_payment_succeeded_agreement_stuck_past_the_crash_window(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);
        $agreement = $this->completeInitialCheckout($workspace, $owner, 1);

        // Force it back to payment_succeeded, aged past the reconciliation
        // job's own bounded threshold — simulating the exact crash window
        // (checkout verified -> payment_succeeded persisted -> process
        // dies before performVerifiedAllocation() runs).
        \Illuminate\Support\Facades\DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update([
            'state' => 'payment_succeeded',
            'current_allocation_count' => 0,
            'updated_at' => now()->subMinutes(31),
        ]);

        $due = app(AdditionalBusinessSlotAgreementRepository::class)->findRequiringAllocationRecovery(30);
        $this->assertTrue($due->contains(fn ($a) => (int) $a->id === (int) $agreement->id));

        app(\App\Jobs\Usage\ReconcileSlotAgreementAllocation::class)->handle(
            app(AdditionalBusinessSlotAgreementRepository::class),
            app(UsageBillingCheckoutManager::class),
        );

        $recovered = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $recovered->state);
        $this->assertSame(1, $recovered->current_allocation_count);
    }

    /**
     * M4 contract §11/§21 (Correction Round 2 §D) — a mid-period increase
     * that exhausts its three attempts releases exactly its own reserved
     * delta, atomically tied to the unique terminal failed-transition
     * insert; a later, independent increase computes correctly against the
     * corrected baseline, and the final RFC-004 count matches exactly the
     * sum of the successfully-paid deltas.
     */
    public function test_terminal_mid_period_increase_failure_releases_its_reservation_for_a_later_increase(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);
        $agreement = $this->completeInitialCheckout($workspace, $owner, 1);
        $manager = app(UsageBillingCheckoutManager::class);

        // Increase A: target 1 -> 2 (delta 1), every attempt declines.
        $this->gateway->confirmPaymentIntentOutcomes['*'] = 'declined';
        $this->gateway->paymentIntentOutcomes['*'] = 'declined';
        $resultA = $manager->requestSlotAgreementIncrease($agreement, 2, (string) \Illuminate\Support\Str::uuid(), $owner->id);
        $chargeA = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($resultA->renewalChargeId);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Failed, $resultA->state);

        $afterFirstAttempt = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(2, $afterFirstAttempt->target_allocation_count, 'Reservation bumped on creation, before any retry.');

        $manager->retrySlotRenewalAsOwner($chargeA, $owner->id);
        $manager->retrySlotRenewalAsOwner(app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($chargeA->id), $owner->id);

        $afterTerminalFailure = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(1, $afterTerminalFailure->target_allocation_count, 'Terminal failure releases exactly the frozen delta back to the pre-reservation baseline.');
        $this->assertSame(1, $afterTerminalFailure->current_allocation_count, 'Never allocated — current_allocation_count is untouched.');

        // Increase B: a later, independent increase, computed against the
        // corrected (released) baseline.
        $this->gateway->paymentIntentOutcomes['*'] = 'succeeded';
        $resultB = $manager->requestSlotAgreementIncrease($afterTerminalFailure, 2, (string) \Illuminate\Support\Str::uuid(), $owner->id);
        $chargeB = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($resultB->renewalChargeId);
        $this->assertSame(1, $chargeB->allocation_delta);
        $this->assertSame(\App\Enums\Usage\FundingAttemptState::Succeeded, $resultB->state);

        $final = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(2, $final->target_allocation_count);
        $this->assertSame(2, $final->current_allocation_count);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots, 'Exactly the sum of the successfully-paid deltas (1 initial + 1 from B) — never including A\'s failed delta.');
    }

    public function test_crash_replay_after_charge_succeeded_reruns_allocation_idempotently(): void
    {
        [$workspace, $owner] = $this->entitledWorkspace(0);
        // Core's own [0,1,2] additional-slot cap (RFC-004) leaves room for
        // exactly one more slot above the 1 already allocated.
        $agreement = $this->completeInitialCheckout($workspace, $owner, 1);
        $manager = app(UsageBillingCheckoutManager::class);

        $changeOperationId = (string) \Illuminate\Support\Str::uuid();
        $result = $manager->requestSlotAgreementIncrease($agreement, 2, $changeOperationId, $owner->id);
        $charge = app(\App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result->renewalChargeId);

        $assignmentAfterFirst = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);

        // Replay: the charge is already Succeeded; a second webhook
        // delivery must still idempotently re-run allocation without
        // creating a second RFC-004 transition row.
        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.succeeded',
            'provider_object_id' => $charge->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $manager->confirmSlotRenewalChargeFromWebhook($charge, $event);

        $assignmentAfterReplay = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame($assignmentAfterFirst->additional_business_slots, $assignmentAfterReplay->additional_business_slots);

        $transitionCount = \Illuminate\Support\Facades\DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('payment_idempotency_key', hash('sha256', 'slot-renewal-allocation:'.$charge->local_idempotency_key))
            ->count();
        $this->assertSame(1, $transitionCount);
    }
}
