<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspaceAdditionalBusinessSlotsChanged;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Events\Entitlement\WorkspacePlanCatalogPricingChanged;
use App\Events\Entitlement\WorkspacePlanChanged;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Library\Business\BusinessManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Currency;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\Feature\Entitlement\Concerns\PinsBoundedBusinessSlotCatalog;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — Business / client-account capacity
 * (contract §7.4; RFC-004 §33.2). Core and Growth hold exactly ONE
 * Business and offer no additional Business slot at any price; Agency is
 * unlimited. The unchanged decideBusinessSlotCapacity() engine enforces
 * this from the corrected catalog values at every Business-count-increasing
 * seam — ordinary creation, cross-Workspace reassignment and legacy
 * onboarding. T-BIZ-1, T-BIZ-2.
 *
 * Correction round 1: nothing can price, allocate or pay for an extra
 * Business on a catalog row that offers none (business_slot_max <=
 * business_slot_included) — the ratio is refused at the catalog, and every
 * allocation path derives its limit from the row, not from the tier name.
 */
class BusinessAccountCapacityTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use PinsBoundedBusinessSlotCatalog;
    use RefreshDatabase;

    public static function boundedTiers(): array
    {
        return ['core' => [WorkspacePlanTier::Core], 'growth' => [WorkspacePlanTier::Growth]];
    }

    #[DataProvider('boundedTiers')]
    public function test_the_catalog_holds_one_business_and_no_priced_additional_business_slot(WorkspacePlanTier $tier): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', $tier->value)->sole();

        $this->assertSame(1, $catalog->business_slot_included);
        $this->assertSame(1, $catalog->business_slot_max);
        $this->assertFalse($catalog->unlimited_business_slots);
        $this->assertNull($catalog->additional_business_slot_price_ratio, 'No additional Business slot can be priced.');
        $this->assertSame(3, $catalog->location_slot_included);
        $this->assertSame(5, $catalog->location_slot_max);
        $this->assertFalse($catalog->unlimited_location_slots);
        $this->assertSame('0.5000', $catalog->additional_location_slot_price_ratio);
    }

    public function test_agency_stays_unlimited_for_businesses_and_locations(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'agency')->sole();

        $this->assertTrue($catalog->unlimited_business_slots);
        $this->assertTrue($catalog->unlimited_location_slots);
        $this->assertNull($catalog->location_slot_max);
        $this->assertNull($catalog->additional_location_slot_price_ratio);
    }

    // T-BIZ-1
    #[DataProvider('boundedTiers')]
    public function test_the_first_business_is_allowed_and_a_second_is_denied(WorkspacePlanTier $tier): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->ensureRequiredAppConfigRowsExist();
        $this->assignTier($workspace, $tier);

        $manager = app(WorkspaceManager::class);
        $manager->createBusinessInWorkspace((int) $customer->user_id, $customer, $workspace, $this->businessAttributes(['name' => 'First Co']));

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);
        $this->assertFalse($decision->allowed);
        $this->assertSame('business_slot_limit_exceeded', $decision->denialReason, 'No allocation step exists — Agency is the only path.');

        try {
            $manager->createBusinessInWorkspace((int) $customer->user_id, $customer, $workspace, $this->businessAttributes(['name' => 'Second Co']));
            $this->fail('A second Business on Core/Growth must be denied.');
        } catch (BusinessSlotLimitExceededException) {
        }

        $this->assertSame(1, DB::table('businesses')->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_agency_workspace_holds_several_businesses(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $manager = app(WorkspaceManager::class);

        foreach (['Client Two', 'Client Three', 'Client Four'] as $name) {
            $manager->createBusinessInWorkspace((int) $customer->user_id, $customer, $workspace, $this->businessAttributes(['name' => $name]));
        }

        $this->assertSame(4, DB::table('businesses')->where('workspace_id', $workspace->id)->count());
        $this->assertTrue(app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace)->allowed);
    }

    // T-BIZ-2
    public function test_a_workspace_already_holding_several_businesses_keeps_them_all_and_is_denied_only_new_ones(): void
    {
        [$customer, $first, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $second = $this->addBusiness($customer, $workspace, 'Grandfathered Two');
        $third = $this->addBusiness($customer, $workspace, 'Grandfathered Three');

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);
        $this->assertSame(3, $decision->currentBusinessCount);
        $this->assertFalse($decision->allowed);

        foreach ([$first, $second, $third] as $business) {
            $this->assertTrue(app(WorkspaceManager::class)->userCanAccessBusiness((int) $customer->user_id, $business), "{$business->name} stays reachable.");
            $this->assertSame('active', DB::table('businesses')->where('id', $business->id)->value('status'), 'Nothing is deactivated.');
        }

        $this->expectExceptionSafely(BusinessSlotLimitExceededException::class, fn () => app(WorkspaceManager::class)->createBusinessInWorkspace((int) $customer->user_id, $customer, $workspace, $this->businessAttributes(['name' => 'Fourth'])));
    }

    public function test_a_cross_workspace_reassignment_into_a_full_core_workspace_is_denied(): void
    {
        [$owner, , $target] = $this->tenant(WorkspacePlanTier::Core, 'Target Co', 'Target');
        [$sourceOwner, $moving, $source] = $this->tenant(WorkspacePlanTier::Agency, 'Moving Co', 'Source');
        $this->member($target, $sourceOwner->user, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);

        $this->expectExceptionSafely(BusinessSlotLimitExceededException::class, fn () => app(WorkspaceManager::class)->reassignBusiness((int) $sourceOwner->user_id, $moving, $target));

        $this->assertSame($source->id, (int) $moving->fresh()->workspace_id, 'The Business did not move.');
    }

    public function test_the_legacy_onboarding_path_cannot_create_a_second_business(): void
    {
        [$customer, $existing] = $this->tenant(WorkspacePlanTier::Core);

        $this->expectExceptionSafely(BusinessSlotLimitExceededException::class, fn () => app(BusinessManager::class)->createOrUpdateOnboardingBusiness($customer, null, $this->businessAttributes(['name' => 'Onboarding Second'])));

        $this->assertSame(1, DB::table('businesses')->where('customer_id', $customer->user_id)->count());
    }

    public function test_an_extra_business_slot_cannot_be_bought_or_paid_for_on_core(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->expectExceptionSafely(\Throwable::class, fn () => app(UsageBillingCheckoutManager::class)->quoteAdditionalSlotAgreement($workspace, 1, (int) $customer->user_id));
        $this->assertSame(0, DB::table('additional_business_slot_agreements')->count(), 'No quote is created.');

        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['is_complimentary' => false]);
        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->platformAdminId(), 'paid second Business'));
    }

    // --- The commercial guard: extra-Business billing stays dead ----------

    #[DataProvider('boundedTiers')]
    public function test_the_corrected_catalog_refuses_an_additional_business_slot_price_ratio(WorkspacePlanTier $tier): void
    {
        Event::fake([WorkspacePlanCatalogPricingChanged::class]);
        $catalog = WorkspacePlanCatalog::where('tier', $tier->value)->sole();
        $admin = $this->platformAdminId();

        try {
            app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, '0.5000', $admin, 'Price a second Business.');
            $this->fail('A catalog row with no additional Business capacity must refuse a price ratio.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('offers no additional Business capacity', $e->getMessage());
        }

        $this->assertNull($catalog->fresh()->additional_business_slot_price_ratio);
        $this->assertSame(0, DB::table('workspace_plan_catalog_pricing_changes')->where('workspace_plan_catalog_id', $catalog->id)->count(), 'No pricing audit row.');
        Event::assertNotDispatched(WorkspacePlanCatalogPricingChanged::class);

        // The refusal is the ratio alone: the same change without one is accepted and audited.
        app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, null, $admin, 'No ratio.');
        $this->assertSame(1, DB::table('workspace_plan_catalog_pricing_changes')->where('workspace_plan_catalog_id', $catalog->id)->count());
    }

    public function test_agency_still_refuses_an_additional_business_slot_price_ratio(): void
    {
        $catalog = WorkspacePlanCatalog::where('tier', 'agency')->sole();

        try {
            app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, '0.5000', $this->platformAdminId(), 'Price an Agency slot.');
            $this->fail('Agency must refuse a price ratio.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not support an additional-Business-slot price ratio', $e->getMessage());
        }

        $this->assertSame(0, DB::table('workspace_plan_catalog_pricing_changes')->where('workspace_plan_catalog_id', $catalog->id)->count());
    }

    public function test_a_catalog_row_that_offers_additional_business_capacity_keeps_the_generic_arithmetic(): void
    {
        $this->pinBoundedBusinessSlotCatalog(['core']);
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->sole();

        $updated = app(EntitlementManager::class)->updateCatalogPricing($catalog, null, null, '0.5000', $this->platformAdminId(), 'Bounded 3/5 row.');
        $this->assertSame('0.5000', (string) $updated->fresh()->getRawOriginal('additional_business_slot_price_ratio'));

        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $allocated = app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 2, $this->platformAdminId(), 'max − included = 2');
        $this->assertSame(2, $allocated->additional_business_slots);

        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 3, $this->platformAdminId(), 'beyond the maximum'));
    }

    #[DataProvider('boundedTiers')]
    public function test_a_platform_administrator_cannot_allocate_an_additional_business_slot(WorkspacePlanTier $tier): void
    {
        Event::fake([WorkspaceAdditionalBusinessSlotsChanged::class]);
        // Complimentary, so no pricing gate stands in front of the capacity rule.
        [, , $workspace] = $this->tenant($tier);

        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->platformAdminId(), 'A second Business.'));

        $this->assertNoAdditionalBusinessSlotChange($workspace);
    }

    #[DataProvider('boundedTiers')]
    public function test_a_payment_verified_allocation_cannot_add_an_additional_business_slot(WorkspacePlanTier $tier): void
    {
        Event::fake([WorkspaceAdditionalBusinessSlotsChanged::class]);
        [$customer, , $workspace] = $this->tenant($tier);

        // Even with every price in place — written directly, because
        // updateCatalogPricing() now refuses the ratio — the capacity rule
        // alone refuses the allocation.
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        DB::table('workspace_plan_catalog')->where('tier', $tier->value)->update(['price' => '49.00', 'currency_id' => $currency->id, 'additional_business_slot_price_ratio' => '0.5000']);
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['is_complimentary' => false]);

        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->allocateAdditionalBusinessSlotsFromVerifiedPayment(
            $workspace, 1, (int) $customer->user_id, (int) $workspace->id, 'slice-1a-cr1-' . $tier->value, 'pi_slice_1a_cr1',
        ));

        $this->assertNoAdditionalBusinessSlotChange($workspace);
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')->whereNotNull('payment_idempotency_key')->count());
        $this->assertSame(0, DB::table('additional_business_slot_agreements')->count(), 'No agreement or payment row is involved.');
    }

    public function test_a_first_assignment_or_a_plan_change_cannot_carry_an_additional_business_slot(): void
    {
        Event::fake([WorkspacePlanAssigned::class, WorkspacePlanChanged::class, WorkspaceAdditionalBusinessSlotsChanged::class]);
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $unassigned = $this->createWorkspace($customer->user);

        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->assignFirstPlan($unassigned, WorkspacePlanTier::Core, $this->platformAdminId(), 'With a second Business.', true, 1));
        $this->assertSame(0, DB::table('workspace_plan_assignments')->where('workspace_id', $unassigned->id)->count());

        [, , $agency] = $this->tenant(WorkspacePlanTier::Agency);
        $agencyCatalogId = (int) DB::table('workspace_plan_assignments')->where('workspace_id', $agency->id)->value('workspace_plan_catalog_id');

        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => app(EntitlementManager::class)->changePlan($agency, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Down to Growth with a slot.', 1));
        $this->assertSame($agencyCatalogId, (int) DB::table('workspace_plan_assignments')->where('workspace_id', $agency->id)->value('workspace_plan_catalog_id'), 'The plan did not change.');
        $this->assertNoAdditionalBusinessSlotChange($agency);
        Event::assertNotDispatched(WorkspacePlanAssigned::class, fn ($event) => $event->workspaceId === $unassigned->id);
        Event::assertNotDispatched(WorkspacePlanChanged::class);
    }

    public function test_a_stale_additional_business_slot_counter_grants_nothing_and_can_only_be_reduced(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        // A counter left from the superseded 3/5 catalog (no runtime path can write it now).
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['additional_business_slots' => 2]);
        $manager = app(EntitlementManager::class);

        $decision = $manager->decideBusinessSlotCapacity($workspace);
        $this->assertSame(1, $decision->effectiveCapacity, 'business_slot_max = 1 caps it: Core is exactly one Business.');
        $this->assertFalse($decision->allowed);

        $this->assertSame(2, $manager->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Core to Growth keeps the value.')->additional_business_slots);
        $this->assertSame(1, $manager->setAdditionalBusinessSlots($workspace, 1, $this->platformAdminId(), 'Reduce.')->additional_business_slots);
        $this->expectExceptionSafely(InvalidAdditionalBusinessSlotsException::class, fn () => $manager->setAdditionalBusinessSlots($workspace, 2, $this->platformAdminId(), 'Raise it back.'));
        $this->assertSame(0, $manager->setAdditionalBusinessSlots($workspace, 0, $this->platformAdminId(), 'Clear.')->additional_business_slots);
    }

    private function assertNoAdditionalBusinessSlotChange(\App\Models\Workspace $workspace): void
    {
        $this->assertSame(0, (int) DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->value('additional_business_slots'));
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->where('transition_type', 'additional_business_slots_changed')->count(), 'No allocation audit row.');
        Event::assertNotDispatched(WorkspaceAdditionalBusinessSlotsChanged::class);
    }

    private function expectExceptionSafely(string $exception, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, 'Unexpected exception: ' . get_class($thrown) . ' ' . $thrown->getMessage());

            return;
        }

        $this->fail("Expected {$exception}.");
    }
}
