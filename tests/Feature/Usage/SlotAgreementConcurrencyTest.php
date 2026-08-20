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
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §23's forced-race scenarios. True multi-process concurrency
 * is not exercised here (matching this repository's own established
 * sequential-double-invocation precedent for proving idempotent/
 * serialized outcomes, e.g. UsageWalletManagerConcurrencyTest) — instead,
 * each scenario drives the same race deterministically, sequentially, and
 * asserts the single-effect guarantee the real lock/idempotency-key
 * mechanism provides regardless of arrival order.
 *
 * Strengthened per Correction Round 1 §F: an immediate synchronous
 * success and a later webhook success converge on the identical
 * performVerifiedAllocation() outcome, never a duplicate allocation.
 */
class SlotAgreementConcurrencyTest extends TestCase
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

    private function checkoutPendingAgreement(): array
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
        // to read the row would each see under a real concurrent arrival,
        // since neither manager-level call itself re-locks the agreement
        // row for this already-allocation_pending case (only RFC-004's own
        // row lock + payment_idempotency_key uniqueness, exercised inside
        // EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment(),
        // is what actually serializes the underlying allocation).
        $reconciliationView = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $administratorView = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $manager = app(UsageBillingCheckoutManager::class);

        // Reconciliation-style call arrives first (no administrator actor).
        $manager->performVerifiedAllocation($reconciliationView);

        // The administrator's own manual-allocation attempt, from its own
        // independently-read (now stale) allocation_pending snapshot, must
        // be absorbed as a no-op by RFC-004's own idempotency guarantee —
        // never a second transition, never a doubled slot count.
        $manager->allocateSlotAgreementAsAdministrator($administratorView, $admin->id, 'Reconciliation follow-up.');

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame(SlotAgreementState::Completed, $refreshed->state);

        $allocationTransitionCount = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'additional_business_slots_changed')
            ->count();
        $this->assertSame(1, $allocationTransitionCount);

        $assignment = app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id);
        $this->assertSame(2, $assignment->additional_business_slots);
    }
}
