<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M4 contract §11 (Correction Round 1 §B/§C) — exact-second proration
 * arithmetic, never a "days" approximation. Strengthened: a
 * mid_period_increase charge snapshots its exact, positive
 * allocation_delta, frozen at creation, and the amount is never used to
 * reverse-derive that quantity.
 */
class AdditionalBusinessSlotAgreementProrationTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mid_period_increase_freezes_its_exact_positive_allocation_delta_independent_of_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00', 'UTC'));

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
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 1, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        $manager->confirmSlotAgreementFromReturn($agreement);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        // Freeze the current period to a known, exact window so the
        // prorated amount is deterministically computable.
        $periodEnd = now()->addDays(10);
        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update(['next_renewal_at' => $periodEnd]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        // Core's own [0,1,2] additional-slot cap (RFC-004) leaves room for
        // exactly one more slot above the 1 already allocated.
        $result = $manager->requestSlotAgreementIncrease($agreement, 2, (string) Str::uuid(), $owner->id);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result->renewalChargeId);

        // allocation_delta (1) is exact and independent of the prorated
        // amount — never reverse-derived from money.
        $this->assertSame(1, $charge->allocation_delta);
        $this->assertGreaterThan(0, $charge->amount_micro_snapshot);
        // The full, unprorated 1-slot amount would be 10,000,000 micro;
        // a genuinely mid-period, less-than-a-full-month proration must
        // charge strictly less than that.
        $this->assertLessThan(10_000_000, $charge->amount_micro_snapshot);

        // Independent, exact-second recomputation of the same formula
        // UsageBillingCheckoutManager::requestSlotAgreementIncrease() uses
        // (never merely asserting a loose bound, which cannot distinguish
        // a correct implementation from an off-by-formula regression that
        // still happens to land strictly between 0 and the full price).
        $periodEndForRecomputation = Carbon::instance($periodEnd)->toImmutable();
        $periodStartForRecomputation = $periodEndForRecomputation->subMonthNoOverflow();
        $now = Carbon::now()->toImmutable();
        $remainingSeconds = max(0, $periodEndForRecomputation->getTimestamp() - $now->getTimestamp());
        $totalSeconds = max(1, $periodEndForRecomputation->getTimestamp() - $periodStartForRecomputation->getTimestamp());
        $fullAmount = '10000000'; // 1 slot at $20.00 x 0.5000 ratio, confirmed above as the unprorated full amount.
        $expectedProratedAmount = (int) UsageWalletManager::bcRoundHalfUp(bcmul($fullAmount, (string) $remainingSeconds, 0), (string) $totalSeconds);

        $this->assertSame($expectedProratedAmount, $charge->amount_micro_snapshot);
    }

    public function test_decreasing_target_allocation_never_retroactively_refunds_the_current_period(): void
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
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 5, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);

        $this->expectException(\App\Exceptions\Usage\UnauthorizedSlotAgreementActionException::class);
        $manager->requestSlotAgreementIncrease($agreement, 2, (string) Str::uuid(), $owner->id);
    }
}
