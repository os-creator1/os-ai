<?php

namespace Tests\Feature\Entitlement;

use App\Console\Commands\AdditionalBusinessSlotRetirementPreflight;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\SlotAgreementState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Models\AdditionalBusinessSlotRenewalCharge;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Entitlement\Concerns\PinsBoundedBusinessSlotCatalog;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 11 — Retire Additional-Business-Slots Flow.
 *
 * Covers exactly the changed surface: the read-only preflight command's
 * exact predicate/report/durable-artifact behavior, and that the
 * new-purchase checkout entry point is genuinely unreachable while every
 * existing-holder route (show, increase, renewal retry, cancellation)
 * remains exactly as before. Does not retest UsageBillingCheckoutManager's
 * own billing algorithms — those are unchanged and covered elsewhere.
 */
class AdditionalBusinessSlotRetirementPreflightTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use PinsBoundedBusinessSlotCatalog;
    use RefreshDatabase;

    private FakePaymentProviderGateway $gateway;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinBoundedBusinessSlotCatalog();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);

        // Base catalog pricing must exist BEFORE any plan is assigned
        // (assignFirstPlan() asserts it) — done once, here, rather than
        // per-fixture-helper.
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $this->platformAdminId(), 'Fixture pricing.');
    }

    /**
     * tenant()'s own plan assignment is complimentary, which
     * EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
     * refuses to allocate paid slots against
     * (ComplimentaryWorkspaceCannotAllocatePaidSlotsException). A real
     * slot-agreement purchase needs a genuinely PAID plan assignment, so
     * this mirrors tenant() with is_complimentary=false.
     *
     * @return array{0: Customer, 1: Workspace}
     */
    private function paidTenant(string $businessName, string $workspaceName): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $workspaceName]);
        $this->addBusiness($customer, $workspace, $businessName);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Fixture paid plan assignment.', false, 0);

        return [$customer, $workspace->fresh()];
    }

    /**
     * Drives a real quote -> checkout -> confirm flow (the same
     * established pattern AdditionalBusinessSlotAgreementCancellationTest
     * uses) against a paidTenant() Workspace, so the resulting agreement is
     * genuinely `Completed` with real FK linkage, and its owning Customer
     * can be used with authenticateAs() for HTTP-level assertions.
     *
     * @return array{0: Customer, 1: Workspace, 2: AdditionalBusinessSlotAgreement}
     */
    private function completedAgreementFor(Customer $customer, Workspace $workspace): array
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, (int) $customer->user->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, (int) $customer->user->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        $manager->confirmSlotAgreementFromReturn($agreement);

        return [$customer, $workspace, app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id)];
    }

    /**
     * Stops after the quote — a non-matching state (`quote_created`, not
     * `completed`) that the preflight predicate must exclude.
     */
    private function quoteOnlyAgreementFor(Workspace $workspace, int $actorUserId): AdditionalBusinessSlotAgreement
    {
        $quote = app(UsageBillingCheckoutManager::class)->quoteAdditionalSlotAgreement($workspace, 2, $actorUserId);

        return app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
    }

    // ------------------------------------------------------------------
    // PREFLIGHT — ZERO HOLDERS
    // ------------------------------------------------------------------

    public function test_zero_holders_reports_zero_and_requires_no_decision(): void
    {
        $report = app(AdditionalBusinessSlotRetirementPreflight::class)->buildReport();

        $this->assertSame(0, $report['active_holder_count']);
        $this->assertFalse($report['commercial_decision_required']);
        $this->assertSame([], $report['agreements']);
        $this->assertSame("state = 'completed' AND cancellation_effective_at IS NULL", $report['predicate']);
    }

    public function test_zero_holders_command_succeeds_and_saves_a_durable_report(): void
    {
        Storage::fake('local');

        $this->artisan('usage:additional-business-slot-retirement-preflight')->assertExitCode(0);

        $files = Storage::disk('local')->files('reports');
        $this->assertNotEmpty($files, 'A durable report artifact must be saved even for the zero-holder case.');

        $saved = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame(0, $saved['active_holder_count']);
        $this->assertFalse($saved['commercial_decision_required']);
    }

    // ------------------------------------------------------------------
    // PREFLIGHT — ONE/MULTIPLE HOLDERS
    // ------------------------------------------------------------------

    public function test_exact_matching_rows_are_reported_and_non_matching_states_excluded(): void
    {
        [$tenantCustomerA, $workspaceA] = $this->paidTenant('Alpha Biz', 'Alpha Account');
        [$customerA, $workspaceA, $completed] = $this->completedAgreementFor($tenantCustomerA, $workspaceA);

        // A quote-only agreement (state=quote_created) under a SEPARATE
        // Workspace — must be excluded, it never reached `completed`.
        [$customerB, , $workspaceB] = $this->tenant(WorkspacePlanTier::Core, 'Beta Biz', 'Beta Account');
        $quoteOnly = $this->quoteOnlyAgreementFor($workspaceB, (int) $customerB->user->id);

        // A `completed` agreement that has since been cancelled
        // (cancellation_effective_at set) — must ALSO be excluded, per the
        // exact predicate.
        [$tenantCustomerC, $workspaceC] = $this->paidTenant('Gamma Biz', 'Gamma Account');
        [, , $cancelledCompleted] = $this->completedAgreementFor($tenantCustomerC, $workspaceC);
        DB::table('additional_business_slot_agreements')
            ->where('id', $cancelledCompleted->id)
            ->update(['cancellation_effective_at' => now()->addDays(30)]);

        $snapshotBefore = DB::table('additional_business_slot_agreements')->orderBy('id')->get();

        $report = app(AdditionalBusinessSlotRetirementPreflight::class)->buildReport();

        $this->assertSame(1, $report['active_holder_count']);
        $this->assertTrue($report['commercial_decision_required']);

        $reportedIds = collect($report['agreements'])->pluck('agreement_id')->all();
        $this->assertSame([$completed->id], $reportedIds, 'Only the genuinely completed, non-cancelled agreement is reported.');
        $this->assertNotContains($quoteOnly->id, $reportedIds);
        $this->assertNotContains($cancelledCompleted->id, $reportedIds);

        $reported = $report['agreements'][0];
        $this->assertSame($workspaceA->id, $reported['workspace_id']);
        $this->assertSame($workspaceA->uid, $reported['workspace_uid']);
        $this->assertSame($workspaceA->name, $reported['workspace_name']);
        $this->assertSame($completed->current_allocation_count, $reported['current_allocation_count']);
        $this->assertSame($completed->target_allocation_count, $reported['target_allocation_count']);
        $this->assertSame($completed->next_renewal_at->toIso8601String(), $reported['next_renewal_at']);

        // Zero mutations: the preflight is purely read-only.
        $snapshotAfter = DB::table('additional_business_slot_agreements')->orderBy('id')->get();
        $this->assertEquals($snapshotBefore, $snapshotAfter);
    }

    public function test_non_zero_report_states_commercial_treatment_is_unresolved(): void
    {
        [$tenantCustomer, $tenantWorkspace] = $this->paidTenant('Alpha Biz', 'Alpha Account');
        $this->completedAgreementFor($tenantCustomer, $tenantWorkspace);

        $output = $this->artisan('usage:additional-business-slot-retirement-preflight');
        $output->assertExitCode(0);
        $output->expectsOutputToContain('separately-authorized human commercial decision is required');
    }

    // ------------------------------------------------------------------
    // NEW SALE FROZEN — initial checkout AND mid-period increase
    // (review correction: requestSlotAgreementIncrease() is a real new
    // sale of additional paid slot capacity against an existing
    // agreement, not mere existing-holder management)
    // ------------------------------------------------------------------

    public function test_the_checkout_route_no_longer_exists(): void
    {
        $this->assertFalse(
            Route::has('customer.workspaces.additional-business-slots.checkout'),
            'The new-agreement checkout entry point must be genuinely removed, not merely guarded.'
        );
    }

    public function test_the_increase_route_no_longer_exists(): void
    {
        $this->assertFalse(
            Route::has('customer.workspaces.additional-business-slots.increase'),
            'The mid-period paid-increase entry point must be genuinely removed — it buys new slot capacity, exactly like checkout.'
        );
    }

    public function test_posting_to_the_retired_checkout_path_is_unreachable_and_creates_nothing(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Alpha Biz', 'Alpha Account');
        $this->authenticateAs($customer);

        $response = $this->post("/workspaces/{$workspace->uid}/additional-business-slots/checkout", [
            'target_allocation_count' => 2,
        ]);

        $response->assertNotFound();
        $this->assertSame(0, AdditionalBusinessSlotAgreement::count(), 'No QuoteCreated/CheckoutPending agreement — no checkout/provider call could have been initiated.');
    }

    public function test_posting_to_the_retired_increase_path_is_unreachable_and_creates_nothing(): void
    {
        [$tenantCustomer, $tenantWorkspace] = $this->paidTenant('Alpha Biz', 'Alpha Account');
        [$customer, $workspace, $agreement] = $this->completedAgreementFor($tenantCustomer, $tenantWorkspace);
        $this->authenticateAs($customer);

        $originalAllocation = $agreement->target_allocation_count;
        $renewalChargeCountBefore = AdditionalBusinessSlotRenewalCharge::count();

        $response = $this->post("/workspaces/{$workspace->uid}/additional-business-slots/{$agreement->id}/increase", [
            'target_allocation_count' => $originalAllocation + 1,
            'change_operation_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertNotFound();
        $this->assertSame($renewalChargeCountBefore, AdditionalBusinessSlotRenewalCharge::count(), 'Zero new AdditionalBusinessSlotRenewalCharge rows — no provider charge could have been initiated.');
        $this->assertSame($originalAllocation, $agreement->fresh()->target_allocation_count, 'No allocation increase — the retired route must never be reachable to change it.');
    }

    // ------------------------------------------------------------------
    // EXISTING-HOLDER BEHAVIOR PRESERVED (smallest meaningful smoke;
    // increase is deliberately NOT part of this list — it is a retired
    // new-sale path, not a preserved existing-holder action)
    // ------------------------------------------------------------------

    public function test_existing_holder_show_confirm_retry_and_cancellation_routes_remain_reachable(): void
    {
        [$tenantCustomer, $tenantWorkspace] = $this->paidTenant('Alpha Biz', 'Alpha Account');
        [$customer, $workspace, $agreement] = $this->completedAgreementFor($tenantCustomer, $tenantWorkspace);
        $this->authenticateAs($customer);

        $originalAllocation = $agreement->target_allocation_count;

        $this->get(route('customer.workspaces.additional-business-slots.show', $workspace->uid))->assertOk();

        $this->assertTrue(Route::has('customer.workspaces.additional-business-slots.confirm'), 'Confirmation for an agreement whose checkout already started before retirement must remain registered.');
        $this->assertTrue(Route::has('customer.workspaces.additional-business-slots.retry'));

        $this->post(route('customer.workspaces.additional-business-slots.cancel', [
            'workspaceUid' => $workspace->uid,
            'agreement' => $agreement->id,
        ]))->assertRedirect(route('customer.workspaces.additional-business-slots.show', ['workspaceUid' => $workspace->uid]));

        $refreshed = $agreement->fresh();
        $this->assertTrue((bool) $refreshed->cancel_at_period_end, 'Cancellation of an existing paid holder must still work exactly as before.');
        $this->assertSame($originalAllocation, $refreshed->target_allocation_count, 'Cancellation must never itself change the already-paid allocation.');
    }
}
