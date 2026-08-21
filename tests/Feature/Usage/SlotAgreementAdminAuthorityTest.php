<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementTransitionRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §14 — origination denied for administrators; resume/retry,
 * manual allocation, and cancellation each succeed only with a mandatory
 * reason. Asserts the exact identity-provenance split (§14): RFC-004's
 * own transition records actor_user_id === null and
 * requesting_customer_user_id === the original Workspace owner, never
 * the administrator, while M4's own agreement-transition row records the
 * administrator's real id.
 *
 * Strengthened per Correction Round 1 §F: a non-admin actor directly
 * invoking an M4 admin manager method — bypassing the HTTP layer and its
 * admin-only middleware entirely — is denied by the manager's own
 * internal assertPlatformAdministrator() check.
 */
class SlotAgreementAdminAuthorityTest extends TestCase
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

    private function paymentSucceededAgreement(): array
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $nonAdmin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'NonAdmin', 'email' => 'nonadmin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
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
        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['state' => 'payment_succeeded']);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        return [$workspace, $owner, $admin, $nonAdmin, $agreement];
    }

    public function test_administrator_cannot_originate_a_fresh_checkout(): void
    {
        [$workspace, , $admin] = $this->paymentSucceededAgreement();

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->quoteAdditionalSlotAgreement($workspace, 5, $admin->id);
    }

    public function test_manual_allocation_records_identity_provenance_correctly(): void
    {
        [$workspace, $owner, $admin, , $agreement] = $this->paymentSucceededAgreement();

        app(UsageBillingCheckoutManager::class)->allocateSlotAgreementAsAdministrator($agreement, $admin->id, 'Manual recovery.');

        $rfc004Transition = DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->orderByDesc('id')->first();
        $this->assertNull($rfc004Transition->actor_user_id);
        $this->assertSame($owner->id, $rfc004Transition->requesting_customer_user_id);

        $agreementTransition = app(AdditionalBusinessSlotAgreementTransitionRepository::class)->forAgreement((int) $agreement->id)->last();
        $this->assertSame($admin->id, $agreementTransition->actor_user_id);
    }

    public function test_manual_allocation_requires_a_mandatory_reason(): void
    {
        [, , $admin, , $agreement] = $this->paymentSucceededAgreement();

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->allocateSlotAgreementAsAdministrator($agreement, $admin->id, '');
    }

    public function test_a_non_admin_actor_directly_invoking_allocate_as_administrator_is_denied_even_bypassing_http_middleware(): void
    {
        [, , , $nonAdmin, $agreement] = $this->paymentSucceededAgreement();

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->allocateSlotAgreementAsAdministrator($agreement, $nonAdmin->id, 'Attempted.');
    }

    public function test_a_non_admin_actor_directly_invoking_retry_as_administrator_is_denied(): void
    {
        [, , , $nonAdmin, $agreement] = $this->paymentSucceededAgreement();
        $charge = new \App\Models\AdditionalBusinessSlotRenewalCharge(['id' => 999999]);

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->retrySlotRenewalAsAdministrator($charge, $nonAdmin->id, 'Attempted.');
    }

    public function test_administrator_cancellation_requires_a_mandatory_reason(): void
    {
        [, , $admin, , $agreement] = $this->paymentSucceededAgreement();

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $admin->id, null);
    }

    public function test_administrator_cancellation_succeeds_with_a_mandatory_reason(): void
    {
        [, , $admin, , $agreement] = $this->paymentSucceededAgreement();

        $updated = app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, $admin->id, 'Support requested.');

        $this->assertTrue((bool) $updated->cancel_at_period_end);
    }
}
