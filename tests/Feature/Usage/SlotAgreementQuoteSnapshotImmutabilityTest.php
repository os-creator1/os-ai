<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §9 items 2/4/5 — explicitly proves the price x ratio
 * formula: two quotes against catalog rows differing only in
 * additional_business_slot_price_ratio produce proportionally different
 * price_per_slot_micro_snapshot/total_amount_micro_snapshot values.
 * Proves immutability: a later catalog edit changes neither an existing
 * agreement's own frozen snapshots nor its total, while a fresh renewal
 * charge re-snapshots and recomputes from the new price/ratio.
 */
class SlotAgreementQuoteSnapshotImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());
    }

    private function entitledWorkspace(string $ratio): array
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
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, $ratio, $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, 0);

        return [$workspace->fresh(), $owner, $admin];
    }

    public function test_price_per_slot_is_the_base_plan_price_multiplied_by_the_ratio(): void
    {
        // Both fixtures share the single global Core catalog row, so each
        // quote must be taken immediately after its own catalog-pricing
        // setup — before the next entitledWorkspace() call overwrites the
        // catalog's ratio out from under it.
        $manager = app(UsageBillingCheckoutManager::class);

        [$workspaceFullRatio, $ownerFullRatio] = $this->entitledWorkspace('1.0000');
        $fullRatioQuote = $manager->quoteAdditionalSlotAgreement($workspaceFullRatio, 2, $ownerFullRatio->id);

        [$workspaceHalfRatio, $ownerHalfRatio] = $this->entitledWorkspace('0.5000');
        $halfRatioQuote = $manager->quoteAdditionalSlotAgreement($workspaceHalfRatio, 2, $ownerHalfRatio->id);

        // $20.00 base price -> 20,000,000 micro. Ratio 1.0000 -> full
        // price per slot; ratio 0.5000 -> exactly half.
        $this->assertSame(20_000_000, $fullRatioQuote->pricePerSlotMicroSnapshot);
        $this->assertSame(10_000_000, $halfRatioQuote->pricePerSlotMicroSnapshot);
        $this->assertSame($fullRatioQuote->pricePerSlotMicroSnapshot, $halfRatioQuote->pricePerSlotMicroSnapshot * 2);

        $this->assertSame(40_000_000, $fullRatioQuote->totalAmountMicroSnapshot);
        $this->assertSame(20_000_000, $halfRatioQuote->totalAmountMicroSnapshot);
    }

    public function test_a_later_catalog_edit_never_changes_an_already_created_agreements_own_frozen_snapshot(): void
    {
        [$workspace, $owner, $admin] = $this->entitledWorkspace('0.5000');
        $manager = app(UsageBillingCheckoutManager::class);

        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);

        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '40.00', $this->currencyId, '1.0000', $admin->id, 'Price change.');

        $refreshed = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertSame($agreement->price_per_slot_micro_snapshot, $refreshed->price_per_slot_micro_snapshot);
        $this->assertSame($agreement->total_amount_micro_snapshot, $refreshed->total_amount_micro_snapshot);
        $this->assertSame('0.5000', (string) $refreshed->ratio_snapshot);
    }

    public function test_a_renewal_charge_created_after_a_catalog_edit_re_snapshots_from_the_new_price_and_ratio(): void
    {
        [$workspace, $owner, $admin] = $this->entitledWorkspace('0.5000');
        $manager = app(UsageBillingCheckoutManager::class);

        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);

        DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update([
            'state' => 'completed',
            'current_allocation_count' => 2,
            'next_renewal_at' => now()->subMinute(),
        ]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '40.00', $this->currencyId, '1.0000', $admin->id, 'Price change.');

        $manager->createScheduledRenewalCharge($agreement);

        $charge = DB::table('additional_business_slot_renewal_charges')->where('agreement_id', $agreement->id)->first();
        // New price $40.00 x ratio 1.0000 = 40,000,000 micro/slot x 2 slots.
        $this->assertSame(80_000_000, (int) $charge->amount_micro_snapshot);
        $this->assertSame('1.0000', (string) $charge->ratio_snapshot);
    }
}
