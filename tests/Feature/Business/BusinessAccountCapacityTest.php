<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-BIZ-1 and T-BIZ-2 (contract §7.4).
 *
 * A Business is the client-account boundary. Core and Growth hold exactly
 * ONE; Agency holds unlimited. This is enforced by the EXISTING
 * decideBusinessSlotCapacity() engine reading the corrected catalog values
 * — no parallel entitlement engine was created, and the
 * physical-location fields are never consulted for Business capacity.
 */
class BusinessAccountCapacityTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    /** T-BIZ-1 — Core/Growth cannot create a second Business. */
    public function test_core_and_growth_cannot_create_a_second_business(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, , $workspace] = $this->locationTenant($tier);

            $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace);

            $this->assertSame(1, $decision->currentBusinessCount);
            $this->assertSame(1, $decision->includedSlots, "{$tier->value} must include exactly one Business.");
            $this->assertFalse($decision->allowed);
            $this->assertSame('business_slot_limit_exceeded', $decision->denialReason);

            try {
                app(WorkspaceManager::class)->createBusinessInWorkspace(
                    $customer->user_id,
                    $customer,
                    $workspace,
                    $this->businessAttributes(['name' => 'Second Co']),
                );
                $this->fail("{$tier->value} must refuse a second Business.");
            } catch (BusinessSlotLimitExceededException) {
                // expected
            }

            $this->assertSame(
                1,
                (int) DB::table('businesses')->where('workspace_id', $workspace->id)->count(),
                'A refused Business creation must be a true no-op.'
            );
        }
    }

    /** T-BIZ-1 — no allocation can raise the Core/Growth Business ceiling. */
    public function test_no_additional_business_slot_allocation_can_raise_the_core_growth_ceiling(): void
    {
        [, , $workspace] = $this->locationTenant(WorkspacePlanTier::Growth);

        // Even if an allocation existed, business_slot_max is 1, so the
        // effective capacity cannot rise above one.
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $workspace->id)
            ->update(['additional_business_slots' => 2]);

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace->fresh());

        $this->assertFalse($decision->allowed);
        $this->assertSame('business_slot_limit_exceeded', $decision->denialReason);
        $this->assertSame(1, $decision->effectiveCapacity);
    }

    /** T-BIZ-2 — Agency permits multiple Businesses. */
    public function test_agency_permits_multiple_businesses(): void
    {
        [$customer, , $workspace] = $this->agencyTenant();

        foreach (['Client Two', 'Client Three', 'Client Four'] as $name) {
            app(WorkspaceManager::class)->createBusinessInWorkspace(
                $customer->user_id,
                $customer,
                $workspace,
                $this->businessAttributes(['name' => $name]),
            );
        }

        $this->assertSame(4, (int) DB::table('businesses')->where('workspace_id', $workspace->id)->count());

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace->fresh());
        $this->assertTrue($decision->unlimited);
        $this->assertTrue($decision->allowed);
    }

    /**
     * T-BIZ-2 — a Workspace that already held several Businesses keeps them
     * all; only NEW creation is denied. This is the grandfathered-over-
     * capacity state RFC-004 §25.4 defines, and it is why the migration
     * never deletes or deactivates anything.
     */
    public function test_an_existing_over_capacity_workspace_keeps_every_business(): void
    {
        [$customer, , $workspace] = $this->agencyTenant();

        foreach (['Client Two', 'Client Three'] as $name) {
            app(WorkspaceManager::class)->createBusinessInWorkspace(
                $customer->user_id, $customer, $workspace, $this->businessAttributes(['name' => $name]),
            );
        }

        $this->assertSame(3, (int) DB::table('businesses')->where('workspace_id', $workspace->id)->count());

        // Downgrade to Growth: every Business is retained.
        app(EntitlementManager::class)->changePlan(
            $workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgrade for grandfathering test.'
        );

        $this->assertSame(
            3,
            (int) DB::table('businesses')->where('workspace_id', $workspace->id)->count(),
            'A downgrade must never delete or deactivate an existing Business.'
        );

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity(Workspace::query()->findOrFail($workspace->id));

        $this->assertFalse($decision->allowed, 'Only NEW creation is denied.');
        $this->assertSame(3, $decision->currentBusinessCount);
    }

    /**
     * Business capacity must never read the physical-location fields, and
     * location capacity must never read the Business-slot fields. The two
     * were conflated once (RFC-004 §33.1); this asserts they stay separate.
     */
    public function test_business_capacity_and_location_capacity_are_independent(): void
    {
        [, $business, $workspace] = $this->locationTenant();

        // Give the Business plenty of LOCATION capacity.
        $this->setAdditionalLocationSlots($business, 2);
        $this->setGrandfatheredLocationSlots($business, 5);

        // Business capacity is unmoved by any of it.
        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace->fresh());

        $this->assertSame(1, $decision->includedSlots);
        $this->assertSame(0, $decision->additionalSlotsAllocated, 'Location allocations must never appear as Business slots.');
        $this->assertFalse($decision->allowed);

        // And Business slots never raise location capacity.
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $workspace->id)
            ->update(['additional_business_slots' => 2]);

        $locationDecision = app(EntitlementManager::class)->decideLocationSlotCapacity($business->fresh());

        $this->assertSame(3, $locationDecision->includedSlots);
        $this->assertSame(2, $locationDecision->additionalSlotsAllocated, 'Only the location counter feeds location capacity.');
    }
}
