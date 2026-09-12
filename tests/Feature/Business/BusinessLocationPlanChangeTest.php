<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\BusinessLocation;
use App\Models\WorkspaceEntitlementTransition;
use App\Models\WorkspacePlanAssignment;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Eloquent\EloquentBusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — plan downgrades (contract §7.3, §7.5.3;
 * RFC-004 §33.7). EntitlementManager::changePlan() re-evaluates physical-
 * location grandfathering in its OWN transaction, through the one
 * location-capacity algorithm: every Business and location is kept, nothing
 * is archived or hidden, and only new creation is denied. T-LOC-7.
 */
class BusinessLocationPlanChangeTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    public function test_agency_to_core_within_the_limit_grandfathers_nothing(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 2);

        $this->entitlements()->changePlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Downgrade within limit.');

        $this->assertSame(0, (int) $business->fresh()->grandfathered_location_slots);
        $this->assertSame(0, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::CapacityGrandfathered)->count());

        $this->locations()->createLocation($business, $this->locationAttributes('Third'), (int) $customer->user_id);
        $this->assertSame(3, $this->activeCount($business));
        $this->expectExceptionSafely(LocationSlotAllocationRequiredException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id));
    }

    // T-LOC-7
    public function test_agency_to_core_over_capacity_keeps_every_location_and_denies_only_new_ones(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 7);
        $before = BusinessLocation::where('business_id', $business->id)->orderBy('id')->get(['id', 'lifecycle_state', 'name'])->toArray();

        $this->entitlements()->changePlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Downgrade over capacity.');

        $this->assertSame($before, BusinessLocation::where('business_id', $business->id)->orderBy('id')->get(['id', 'lifecycle_state', 'name'])->toArray(), 'Nothing deleted, hidden or auto-archived.');
        $this->assertSame(4, (int) $business->fresh()->grandfathered_location_slots, 'The excess over the 3 included is complimentary.');

        $row = WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::CapacityGrandfathered)->sole();
        $this->assertSame($workspace->id, (int) $row->workspace_id, 'The audit row stays Workspace-scoped.');
        $this->assertSame('plan_change', $row->payload['source']);
        $this->assertSame($business->id, $row->payload['locations'][0]['business_id']);
        $this->assertSame(7, $row->payload['locations'][0]['active_locations']);
        $this->assertSame(4, $row->payload['locations'][0]['to_grandfathered_location_slots']);

        $this->expectExceptionSafely(LocationSlotLimitExceededException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Eighth'), (int) $customer->user_id));

        $this->locations()->archiveLocation($this->locationNamed($business, 'Branch 7'), (int) $customer->user_id);
        $this->assertSame(3, (int) $business->fresh()->grandfathered_location_slots, 'Archiving consumes the complimentary allowance.');
        $this->assertFalse($this->locations()->capacity($business)->allowed);
    }

    public function test_agency_to_growth_keeps_every_business_and_denies_a_new_one(): void
    {
        [$customer, $first, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 1);
        $second = $this->addBusiness($customer, $workspace, 'Client Two');
        $third = $this->addBusiness($customer, $workspace, 'Client Three');

        $this->entitlements()->changePlan($workspace, WorkspacePlanTier::Growth, $this->platformAdminId(), 'Downgrade with several Businesses.');

        foreach ([$first, $second, $third] as $business) {
            $this->assertTrue(app(WorkspaceManager::class)->userCanAccessBusiness((int) $customer->user_id, $business), "{$business->name} stays reachable.");
        }

        $row = WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::CapacityGrandfathered)->sole();
        $this->assertTrue($row->payload['businesses']['grandfathered_over_capacity']);
        $this->assertSame(3, $row->payload['businesses']['count']);

        $this->expectExceptionSafely(BusinessSlotLimitExceededException::class, fn () => app(WorkspaceManager::class)->createBusinessInWorkspace((int) $customer->user_id, $customer, $workspace->fresh(), $this->businessAttributes(['name' => 'Client Four'])));
    }

    public function test_a_later_downgrade_evaluates_grandfathering_fresh_instead_of_restoring_it(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        $this->seedRawActiveLocations($business, 5);
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 2]);

        $this->entitlements()->changePlan($workspace, WorkspacePlanTier::Agency, $this->platformAdminId(), 'Upgrade.');
        $this->assertSame(2, (int) $business->fresh()->grandfathered_location_slots, 'Retained but unused on Agency.');

        $this->locations()->archiveLocation($this->locationNamed($business, 'Seeded 5'), (int) $customer->user_id);
        $this->locations()->archiveLocation($this->locationNamed($business, 'Seeded 4'), (int) $customer->user_id);

        $this->entitlements()->changePlan($workspace->fresh(), WorkspacePlanTier::Core, $this->platformAdminId(), 'Downgrade again.');

        $this->assertSame(0, (int) $business->fresh()->grandfathered_location_slots, 'Three active locations fit; the old allowance is not re-granted.');
        $this->expectExceptionSafely(LocationSlotAllocationRequiredException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Free Slot?'), (int) $customer->user_id));
    }

    public function test_a_failure_during_location_normalization_rolls_the_whole_plan_change_back(): void
    {
        [, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Agency, 7);
        $agencyCatalogId = (int) WorkspacePlanAssignment::where('workspace_id', $workspace->id)->value('workspace_plan_catalog_id');

        $this->app->instance(BusinessLocationRepository::class, new class(new BusinessLocation()) extends EloquentBusinessLocationRepository {
            public function countActiveForUpdate(int $businessId): int
            {
                throw new RuntimeException('Simulated failure while normalizing locations.');
            }
        });

        $this->expectExceptionSafely(RuntimeException::class, fn () => $this->entitlements()->changePlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Doomed downgrade.'));

        $this->assertSame($agencyCatalogId, (int) WorkspacePlanAssignment::where('workspace_id', $workspace->id)->value('workspace_plan_catalog_id'), 'The tier change rolled back.');
        $this->assertSame(0, WorkspaceEntitlementTransition::where('workspace_id', $workspace->id)->where('transition_type', WorkspaceEntitlementTransitionType::PlanChanged)->count());
        $this->assertSame(0, (int) $business->fresh()->grandfathered_location_slots);
        $this->assertSame(7, $this->activeCount($business));
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
