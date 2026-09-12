<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\BusinessAdditionalLocationSlotsChanged;
use App\Exceptions\Entitlement\InvalidAdditionalLocationSlotsException;
use App\Exceptions\Entitlement\LastActiveLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\LocationAllocationCancellationRefusedException;
use App\Exceptions\Entitlement\LocationSlotAllocationRequiredException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Exceptions\Entitlement\PrimaryLocationCannotBeArchivedException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Models\BusinessLocation;
use App\Models\WorkspaceEntitlementTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — physical-location capacity and lifecycle
 * (contract §7.3, §7.3a, §7.5; RFC-004 §33), exercised through the real
 * canonical boundary (BusinessLocationManager) and EntitlementManager.
 *
 * T-LOC-1..5 and T-LOC-11..16, plus the money gate: while Core/Growth
 * prices are unset, no paid location capacity can be activated for a
 * paying account.
 */
class BusinessLocationCapacityTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    public static function boundedTiers(): array
    {
        return ['core' => [WorkspacePlanTier::Core], 'growth' => [WorkspacePlanTier::Growth]];
    }

    // ------------------------------------------------------------------
    // T-LOC-1 — locations 1–3 are included
    // ------------------------------------------------------------------

    #[DataProvider('boundedTiers')]
    public function test_locations_one_to_three_are_created_freely_with_no_allocation(WorkspacePlanTier $tier): void
    {
        [$customer, $business] = $this->locationTenant($tier, 0);

        foreach ([1, 2, 3] as $n) {
            $this->assertTrue($this->locations()->capacity($business)->allowed, "Location {$n} is included.");
            $this->locations()->createLocation($business, $this->locationAttributes("Branch {$n}"), (int) $customer->user_id);
        }

        $decision = $this->locations()->capacity($business);
        $this->assertSame(3, $decision->activeLocationCount);
        $this->assertSame(3, $decision->includedSlots);
        $this->assertSame(0, $decision->additionalSlotsAllocated);
        $this->assertSame(0, (int) $business->fresh()->additional_location_slots, 'No allocation was needed.');
        $this->assertSame(0, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged)->count());
        $this->assertTrue($this->locationNamed($business, 'Branch 1')->is_primary, 'The first location becomes primary.');
    }

    // ------------------------------------------------------------------
    // T-LOC-2 / T-LOC-3 — the 4th and 5th each need an allocation
    // ------------------------------------------------------------------

    #[DataProvider('boundedTiers')]
    public function test_the_fourth_location_needs_an_allocation_and_then_succeeds(WorkspacePlanTier $tier): void
    {
        [$customer, $business] = $this->locationTenant($tier, 3);

        $this->assertSame('location_slot_allocation_required', $this->locations()->capacity($business)->denialReason);

        try {
            $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
            $this->fail('A 4th location must need an allocation.');
        } catch (LocationSlotAllocationRequiredException) {
        }

        $this->assertSame(3, $this->activeCount($business), 'A refused creation writes nothing.');

        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);

        $this->assertSame(4, $this->activeCount($business));
    }

    #[DataProvider('boundedTiers')]
    public function test_the_fifth_location_needs_a_second_allocation(WorkspacePlanTier $tier): void
    {
        [$customer, $business] = $this->locationTenant($tier, 3);
        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);

        $this->expectExceptionSafely(LocationSlotAllocationRequiredException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Fifth'), (int) $customer->user_id));

        $this->grantComplimentaryLocations($business, 2);
        $this->locations()->createLocation($business, $this->locationAttributes('Fifth'), (int) $customer->user_id);

        $this->assertSame(5, $this->activeCount($business));
    }

    // ------------------------------------------------------------------
    // T-LOC-4 — a 6th location needs Agency; no allocation raises it
    // ------------------------------------------------------------------

    #[DataProvider('boundedTiers')]
    public function test_the_sixth_location_is_denied_and_no_allocation_can_raise_it(WorkspacePlanTier $tier): void
    {
        [$customer, $business] = $this->locationTenant($tier, 3);
        $this->grantComplimentaryLocations($business, 2);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
        $this->locations()->createLocation($business, $this->locationAttributes('Fifth'), (int) $customer->user_id);

        $decision = $this->locations()->capacity($business);
        $this->assertFalse($decision->allowed);
        $this->assertSame('location_slot_limit_exceeded', $decision->denialReason);
        $this->assertSame(5, $decision->maximumSlots);

        $this->expectExceptionSafely(LocationSlotLimitExceededException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Sixth'), (int) $customer->user_id));
        $this->expectExceptionSafely(InvalidAdditionalLocationSlotsException::class, fn () => $this->grantComplimentaryLocations($business, 3));

        $this->assertSame(5, $this->activeCount($business));
        $this->assertSame(2, (int) $business->fresh()->additional_location_slots);
    }

    // ------------------------------------------------------------------
    // T-LOC-5 — Agency is unlimited
    // ------------------------------------------------------------------

    public function test_an_agency_business_creates_locations_without_limit(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Agency, 8);

        $decision = $this->locations()->capacity($business);
        $this->assertTrue($decision->unlimited);
        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->maximumSlots);
        $this->assertSame(8, $this->activeCount($business));
        $this->expectExceptionSafely(InvalidAdditionalLocationSlotsException::class, fn () => $this->grantComplimentaryLocations($business, 1));
    }

    // ------------------------------------------------------------------
    // T-LOC-11 — archive frees one slot; a paid allocation is reusable
    // ------------------------------------------------------------------

    public function test_archiving_frees_one_slot_and_the_same_allocation_serves_a_replacement(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
        $this->assertFalse($this->locations()->capacity($business)->allowed);

        $this->locations()->archiveLocation($this->locationNamed($business, 'Fourth'), (int) $customer->user_id);

        $this->assertSame(3, $this->activeCount($business), 'Archiving frees exactly one active slot.');
        $this->assertSame(1, (int) $business->fresh()->additional_location_slots, 'Archiving never cancels the allocation.');

        $this->locations()->createLocation($business, $this->locationAttributes('Replacement'), (int) $customer->user_id);

        $this->assertSame(4, $this->activeCount($business), 'The replacement reuses the same allocation without a new one.');
        $this->assertSame(1, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged)->count(), 'No re-purchase happened.');
    }

    // ------------------------------------------------------------------
    // T-LOC-12 — reactivation runs the full capacity check
    // ------------------------------------------------------------------

    public function test_reactivation_is_refused_when_capacity_is_exhausted_and_allowed_when_it_fits(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->locations()->archiveLocation($this->locationNamed($business, 'Branch 3'), (int) $customer->user_id);
        $this->locations()->createLocation($business, $this->locationAttributes('Branch 4'), (int) $customer->user_id);

        $archived = $this->locationNamed($business, 'Branch 3');
        $this->expectExceptionSafely(LocationSlotAllocationRequiredException::class, fn () => $this->locations()->reactivateLocation($archived, (int) $customer->user_id));
        $this->assertTrue($archived->fresh()->isArchived(), 'A refused reactivation changes nothing.');

        $this->locations()->archiveLocation($this->locationNamed($business, 'Branch 4'), (int) $customer->user_id);
        $this->locations()->reactivateLocation($archived, (int) $customer->user_id);

        $this->assertTrue($archived->fresh()->isActive());
        $this->assertNull($archived->fresh()->archived_at);
    }

    // ------------------------------------------------------------------
    // T-LOC-13 — reducing an allocation only when active locations fit
    // ------------------------------------------------------------------

    public function test_an_allocation_is_reduced_only_once_the_active_locations_fit(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);

        try {
            $this->grantComplimentaryLocations($business, 0);
            $this->fail('A reduction below the active count must be refused.');
        } catch (LocationAllocationCancellationRefusedException $e) {
            $this->assertSame(1, $e->locationsToArchive, 'The refusal says how many to archive first.');
        }

        $this->assertSame(1, (int) $business->fresh()->additional_location_slots, 'A refused reduction is a complete no-op.');

        $this->locations()->archiveLocation($this->locationNamed($business, 'Fourth'), (int) $customer->user_id);
        $this->grantComplimentaryLocations($business, 0);

        $this->assertSame(0, (int) $business->fresh()->additional_location_slots);
    }

    // ------------------------------------------------------------------
    // T-LOC-14 — the primary and the last active location
    // ------------------------------------------------------------------

    public function test_the_primary_is_archived_only_with_a_replacement_and_the_last_active_never(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 2);
        $primary = $this->locationNamed($business, 'Branch 1');
        $other = $this->locationNamed($business, 'Branch 2');

        $this->expectExceptionSafely(PrimaryLocationCannotBeArchivedException::class, fn () => $this->locations()->archiveLocation($primary, (int) $customer->user_id));
        $this->assertTrue($primary->fresh()->isActive());
        $this->assertTrue($primary->fresh()->is_primary);

        $this->locations()->archiveLocation($primary, (int) $customer->user_id, $other);

        $this->assertTrue($primary->fresh()->isArchived());
        $this->assertFalse($primary->fresh()->is_primary);
        $this->assertTrue($other->fresh()->is_primary, 'Reassignment and archive happened together.');

        $this->expectExceptionSafely(LastActiveLocationCannotBeArchivedException::class, fn () => $this->locations()->archiveLocation($other->fresh(), (int) $customer->user_id));
        $this->assertTrue($other->fresh()->isActive());
    }

    // ------------------------------------------------------------------
    // T-LOC-15 — archiving deletes nothing
    // ------------------------------------------------------------------

    public function test_archiving_keeps_the_row_and_everything_attached_to_it(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 2);
        $location = $this->locationNamed($business, 'Branch 2');
        DB::table('business_locations')->where('id', $location->id)->update(['hours' => json_encode(['mon' => [['09:00', '17:00']]])]);
        $before = DB::table('business_locations')->count();

        $this->locations()->archiveLocation($location, (int) $customer->user_id);

        $this->assertSame($before, DB::table('business_locations')->count(), 'No location row was deleted.');
        $row = DB::table('business_locations')->where('id', $location->id)->first();
        $this->assertSame('archived', $row->lifecycle_state);
        $this->assertNotNull($row->archived_at);
        $this->assertSame('Branch 2', $row->name);
        $this->assertNotNull($row->hours, 'Its details survive.');
        $this->assertSame(2, $this->locations()->capacity($business)->activeLocationCount + $this->locations()->capacity($business)->archivedLocationCount);
    }

    // ------------------------------------------------------------------
    // T-LOC-16 — grandfathered excess is consumed; paid is untouched
    // ------------------------------------------------------------------

    public function test_archiving_grandfathered_excess_consumes_it_while_paid_capacity_is_untouched(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 0);
        $this->seedRawActiveLocations($business, 5);
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 2]);

        $decision = $this->locations()->capacity($business);
        $this->assertSame(5, $decision->effectiveCapacity);
        $this->assertFalse($decision->allowed, 'Grandfathered excess never opens room for a new location.');

        $this->locations()->archiveLocation($this->locationNamed($business, 'Seeded 5'), (int) $customer->user_id);

        $this->assertSame(1, (int) $business->fresh()->grandfathered_location_slots, 'The consumed allowance is gone.');
        $this->assertFalse($this->locations()->capacity($business)->allowed, 'It did not become a transferable free slot.');
        $this->assertSame(1, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::CapacityGrandfathered)->count());

        // A paid allocation behaves the opposite way.
        $this->locations()->archiveLocation($this->locationNamed($business, 'Seeded 4'), (int) $customer->user_id);
        $this->assertSame(0, (int) $business->fresh()->grandfathered_location_slots);
        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Paid Fourth'), (int) $customer->user_id);
        $this->locations()->archiveLocation($this->locationNamed($business, 'Paid Fourth'), (int) $customer->user_id);

        $this->assertSame(1, (int) $business->fresh()->additional_location_slots, 'Archiving never decrements paid capacity.');
    }

    // ------------------------------------------------------------------
    // §7.5.1 — four capacity kinds, readable without inference
    // ------------------------------------------------------------------

    public function test_the_decision_exposes_included_additional_grandfathered_and_archived_separately(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Growth, 3);
        $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
        $this->locations()->archiveLocation($this->locationNamed($business, 'Fourth'), (int) $customer->user_id);
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 0]);

        $decision = $this->locations()->capacity($business);

        $this->assertSame(3, $decision->includedSlots);
        $this->assertSame(1, $decision->additionalSlotsAllocated);
        $this->assertSame(0, $decision->grandfatheredSlots);
        $this->assertSame(1, $decision->archivedLocationCount);
        $this->assertSame(3, $decision->activeLocationCount);
        $this->assertSame(1, $decision->remaining());
    }

    // ------------------------------------------------------------------
    // Capacity comes from the tier; the first location is never refused
    // ------------------------------------------------------------------

    public function test_plan_status_does_not_change_location_capacity_which_comes_from_the_tier(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 2);
        $this->entitlements()->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Slice 1A status fixture.');

        $this->locations()->createLocation($business, $this->locationAttributes('Third'), (int) $customer->user_id);
        $this->assertSame(3, $this->activeCount($business), 'A location costs nothing; the tier still bounds it.');
        $this->expectExceptionSafely(LocationSlotAllocationRequiredException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id));
    }

    public function test_a_business_without_a_plan_can_have_its_first_location_and_nothing_more(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->ensureRequiredAppConfigRowsExist();
        $business = $this->addBusiness($customer, $workspace, 'Unassigned Co');

        $this->locations()->createLocation($business, $this->locationAttributes('First'), (int) $customer->user_id);

        $this->assertSame(1, $this->activeCount($business));
        $this->assertSame('workspace_plan_unassigned', $this->locations()->capacity($business)->denialReason);
        $this->expectExceptionSafely(WorkspacePlanUnassignedException::class, fn () => $this->locations()->createLocation($business, $this->locationAttributes('Second'), (int) $customer->user_id));
    }

    // ------------------------------------------------------------------
    // Money gate — no paid location capacity without a price
    // ------------------------------------------------------------------

    public function test_a_paying_account_cannot_activate_an_add_on_location_while_the_price_is_unset(): void
    {
        [, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['is_complimentary' => false]);

        $this->expectExceptionSafely(UndefinedPlanPricingException::class, fn () => $this->grantComplimentaryLocations($business, 1));

        $this->assertSame(0, (int) $business->fresh()->additional_location_slots);
        $this->assertSame(0, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged)->count());
    }

    public function test_only_a_platform_administrator_can_change_an_allocation_and_every_change_is_audited(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        Event::fake([BusinessAdditionalLocationSlotsChanged::class]);

        $this->expectExceptionSafely(AuthorizationException::class, fn () => $this->entitlements()->setAdditionalLocationSlots($business, 1, (int) $customer->user_id, 'self-grant'));
        $this->assertSame(0, (int) $business->fresh()->additional_location_slots, 'A customer can never grant themselves capacity.');

        $this->grantComplimentaryLocations($business, 1);

        $row = WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged)->sole();
        $this->assertSame($workspace->id, (int) $row->workspace_id, 'The audit row stays Workspace-scoped.');
        $this->assertSame($this->platformAdminId(), (int) $row->actor_user_id);
        $this->assertSame($business->id, $row->payload['business_id']);
        $this->assertSame(0, $row->payload['from_additional_location_slots']);
        $this->assertSame(1, $row->payload['to_additional_location_slots']);
        $this->assertTrue($row->payload['complimentary']);
        Event::assertDispatched(BusinessAdditionalLocationSlotsChanged::class, fn ($event) => $event->businessId === $business->id && $event->toAdditionalLocationSlots === 1);

        // An unchanged value is a true no-op.
        $this->grantComplimentaryLocations($business, 1);
        $this->assertSame(1, WorkspaceEntitlementTransition::where('transition_type', WorkspaceEntitlementTransitionType::AdditionalLocationSlotsChanged)->count());
    }

    public function test_a_location_is_never_a_second_business(): void
    {
        [$customer, $business, $workspace] = $this->locationTenant(WorkspacePlanTier::Core, 3);

        $this->assertSame(1, DB::table('businesses')->where('workspace_id', $workspace->id)->count(), 'Three locations, still one Business.');
        $this->assertSame(0, $this->entitlements()->decideBusinessSlotCapacity($workspace)->effectiveCapacity - 1, 'Business capacity is one on Core.');
        $this->assertSame(3, BusinessLocation::where('business_id', $business->id)->count());
    }

    private function expectExceptionSafely(string $exception, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, 'Unexpected exception: ' . $thrown->getMessage());

            return;
        }

        $this->fail("Expected {$exception}.");
    }
}
