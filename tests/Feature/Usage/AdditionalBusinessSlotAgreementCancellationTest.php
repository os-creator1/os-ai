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
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §12 — a cancellation request sets
 * cancel_at_period_end/cancellation_requested_at/cancellation_effective_at
 * without touching next_renewal_at; InitiateSlotAgreementRenewal correctly
 * skips a cancel_at_period_end agreement even while next_renewal_at
 * remains non-null; FinalizeSlotAgreementCancellation transitions to
 * canceled and nulls next_renewal_at only once cancellation_effective_at
 * has passed.
 */
class AdditionalBusinessSlotAgreementCancellationTest extends TestCase
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

    private function completedAgreement(): array
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
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        $manager->confirmSlotAgreementFromReturn($agreement);

        return [$owner, $admin, app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id)];
    }

    public function test_cancellation_request_sets_flags_without_touching_next_renewal_at(): void
    {
        [$owner, , $agreement] = $this->completedAgreement();
        $originalNextRenewal = $agreement->next_renewal_at;

        $updated = app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $owner->id);

        $this->assertTrue((bool) $updated->cancel_at_period_end);
        $this->assertNotNull($updated->cancellation_requested_at);
        $this->assertTrue($updated->cancellation_effective_at->equalTo($originalNextRenewal));
        $this->assertTrue($updated->next_renewal_at->equalTo($originalNextRenewal));
    }

    public function test_a_pending_cancellation_agreement_is_excluded_from_renewal_even_with_a_due_next_renewal_at(): void
    {
        [$owner, , $agreement] = $this->completedAgreement();
        app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $owner->id);

        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['next_renewal_at' => now()->subMinute()]);

        $due = app(AdditionalBusinessSlotAgreementRepository::class)->findDueForRenewal();
        $this->assertFalse($due->contains(fn ($a) => (int) $a->id === (int) $agreement->id));
    }

    public function test_finalization_transitions_to_canceled_and_nulls_next_renewal_at_only_once_effective(): void
    {
        [$owner, , $agreement] = $this->completedAgreement();
        app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        // Not yet effective — no due rows.
        $dueBefore = app(AdditionalBusinessSlotAgreementRepository::class)->findDueForCancellationFinalization();
        $this->assertFalse($dueBefore->contains(fn ($a) => (int) $a->id === (int) $agreement->id));

        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['cancellation_effective_at' => now()->subMinute()]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $dueAfter = app(AdditionalBusinessSlotAgreementRepository::class)->findDueForCancellationFinalization();
        $this->assertTrue($dueAfter->contains(fn ($a) => (int) $a->id === (int) $agreement->id));

        $finalized = app(UsageBillingCheckoutManager::class)->finalizeSlotAgreementCancellation($agreement);

        $this->assertSame(SlotAgreementState::Canceled, $finalized->state);
        $this->assertNull($finalized->next_renewal_at);
    }

    public function test_owner_cancellation_requires_no_reason_but_administrator_does(): void
    {
        [, $admin, $agreement] = $this->completedAgreement();

        $this->expectException(\App\Exceptions\Usage\UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $admin->id, '');
    }
}
