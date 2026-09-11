<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Library\Business\BusinessManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — Business / client-account capacity
 * (contract §7.4; RFC-004 §33.2). Core and Growth hold exactly ONE
 * Business and offer no additional Business slot at any price; Agency is
 * unlimited. The unchanged decideBusinessSlotCapacity() engine enforces
 * this from the corrected catalog values at every Business-count-increasing
 * seam — ordinary creation, cross-Workspace reassignment and legacy
 * onboarding. T-BIZ-1, T-BIZ-2.
 */
class BusinessAccountCapacityTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
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
        $this->expectExceptionSafely(UndefinedPlanPricingException::class, fn () => app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 1, $this->platformAdminId(), 'paid second Business'));
    }

    public function test_a_complimentary_additional_business_slot_grants_no_second_business(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace, 2, $this->platformAdminId(), 'legacy value');

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);
        $this->assertSame(1, $decision->effectiveCapacity, 'business_slot_max = 1 caps it: Core is exactly one Business.');
        $this->assertFalse($decision->allowed);
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
