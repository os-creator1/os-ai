<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Workspace\BusinessReassignedToWorkspace;
use App\Events\Workspace\WorkspaceMembershipBusinessUnassigned;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\LocationAllocationNotPortableException;
use App\Exceptions\Entitlement\LocationSlotLimitExceededException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A, correction round 1 — a cross-Workspace
 * Business reassignment reconciles the Business's physical-location
 * capacity against the TARGET plan inside the reassignment's own
 * transaction (RFC-004 §33.7), through
 * EntitlementManager::reconcileLocationCapacityForReassignment():
 *
 *  - the complimentary allowance is recalculated FRESH from the Business's
 *    current active locations and the target's included capacity — never
 *    carried over — and every location is kept;
 *  - a paid additional-location allocation is never portable: the move is
 *    refused before anything changes, and the allocation is neither
 *    cleared nor transferred;
 *  - the Workspace → Business → business_locations lock order is kept.
 */
class BusinessLocationReassignmentTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    // 1. Agency, 5 active locations → Growth
    public function test_an_agency_business_with_five_locations_arrives_on_growth_grandfathered_by_exactly_its_excess(): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Agency, 5);
        $this->assertSame(0, (int) $business->grandfathered_location_slots);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);

        $this->reassign($customer, $business, $target);

        $moved = $business->fresh();
        $this->assertSame($target->id, (int) $moved->workspace_id);
        $this->assertSame(2, (int) $moved->grandfathered_location_slots, '5 active − 3 included.');
        $this->assertSame(0, (int) $moved->additional_location_slots);
        $this->assertSame(5, $this->activeCount($moved), 'Every location is kept.');

        $decision = $this->entitlements()->decideLocationSlotCapacity($moved);
        $this->assertFalse($decision->allowed);
        $this->assertSame('location_slot_limit_exceeded', $decision->denialReason);

        try {
            $this->locations()->createLocation($moved, $this->locationAttributes('Sixth'), (int) $customer->user_id);
            $this->fail('A sixth location must be refused on Growth.');
        } catch (LocationSlotLimitExceededException) {
        }
        $this->assertSame(5, $this->activeCount($moved));

        $this->assertSame([
            'source' => 'business_reassignment',
            'business_id' => $business->id,
            'source_workspace_id' => $source->id,
            'target_workspace_id' => $target->id,
            'target_plan_catalog_id' => $this->catalogId(WorkspacePlanTier::Growth),
            'active_locations' => 5,
            'included' => 3,
            'from_grandfathered_location_slots' => 0,
            'to_grandfathered_location_slots' => 2,
        ], $this->reassignmentAudit($target));
    }

    // 2. A stale allowance is recalculated, never carried
    public function test_a_stale_allowance_is_recalculated_fresh_against_the_bounded_target(): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Agency, 3);
        // Retained-but-unused on Agency from an earlier bounded plan.
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 2]);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);

        $this->reassign($customer, $business, $target);

        $this->assertSame(0, (int) $business->fresh()->grandfathered_location_slots, '3 active fit Growth: nothing to protect.');
        $audit = $this->reassignmentAudit($target);
        $this->assertSame(2, $audit['from_grandfathered_location_slots']);
        $this->assertSame(0, $audit['to_grandfathered_location_slots']);
        $this->assertSame(3, $audit['active_locations']);
        $this->assertSame($source->id, $audit['source_workspace_id']);
    }

    public function test_a_grandfathered_business_arrives_carrying_exactly_its_current_excess(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->seedRawActiveLocations($business, 1);
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 1]);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);

        $this->reassign($customer, $business, $target);

        $this->assertSame(1, (int) $business->fresh()->grandfathered_location_slots);
        $this->assertSame(4, $this->activeCount($business));
        $audit = $this->reassignmentAudit($target);
        $this->assertSame([1, 1], [$audit['from_grandfathered_location_slots'], $audit['to_grandfathered_location_slots']], 'The target records the allowance it now hosts.');
    }

    // 3. Archived locations stay archived; the ACTIVE count drives it
    public function test_archived_locations_stay_archived_and_only_active_ones_count(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Agency, 6);
        foreach (['Branch 5', 'Branch 6'] as $name) {
            $this->locations()->archiveLocation($this->locationNamed($business, $name), (int) $customer->user_id);
        }
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 3]);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);

        $this->reassign($customer, $business, $target);

        $moved = $business->fresh();
        $this->assertSame(1, (int) $moved->grandfathered_location_slots, '4 active − 3 included.');
        $this->assertSame(4, $this->activeCount($moved));
        $this->assertSame(2, DB::table('business_locations')->where('business_id', $business->id)->where('lifecycle_state', 'archived')->count(), 'Archived locations stay archived.');
        $this->assertSame(4, $this->reassignmentAudit($target)['active_locations']);
    }

    public function test_an_unlimited_target_keeps_the_allowance_unused_and_writes_nothing(): void
    {
        [$customer, $business] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $this->seedRawActiveLocations($business, 1);
        DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => 1]);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Agency);

        $this->reassign($customer, $business, $target);

        $this->assertSame(1, (int) $business->fresh()->grandfathered_location_slots, 'Retained but unused; a later bounded plan re-evaluates it fresh.');
        $this->assertNull($this->reassignmentAudit($target));
    }

    // 4. A paid location allocation is not portable
    public static function targetTiers(): array
    {
        return ['growth' => [WorkspacePlanTier::Growth], 'agency' => [WorkspacePlanTier::Agency]];
    }

    #[DataProvider('targetTiers')]
    public function test_a_business_holding_an_additional_location_allocation_cannot_move_and_nothing_changes(WorkspacePlanTier $targetTier): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $business = $this->grantComplimentaryLocations($business, 1);
        $this->locations()->createLocation($business, $this->locationAttributes('Fourth'), (int) $customer->user_id);
        $staff = $this->createCustomer();
        $this->assign($this->member($source, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);
        $target = $this->emptyWorkspace($customer, $targetTier);

        $before = $this->snapshot($business);
        Event::fake([BusinessReassignedToWorkspace::class, WorkspaceMembershipBusinessUnassigned::class]);

        try {
            $this->reassign($customer, $business, $target);
            $this->fail('A Business with an additional-location allocation must not move.');
        } catch (LocationAllocationNotPortableException $e) {
            $this->assertSame($business->id, $e->businessId);
            $this->assertSame(1, $e->additionalLocationSlots);
        }

        $this->assertSame($before, $this->snapshot($business), 'Business, allocation, grants, transitions and locations are untouched.');
        $this->assertSame($source->id, (int) $business->fresh()->workspace_id);
        Event::assertNotDispatched(BusinessReassignedToWorkspace::class);
        Event::assertNotDispatched(WorkspaceMembershipBusinessUnassigned::class);
    }

    // 5. Same-Workspace reassignment stays a no-op
    public function test_a_same_workspace_reassignment_is_still_an_untouched_no_op(): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $business = $this->grantComplimentaryLocations($business, 1);
        $before = $this->snapshot($business);
        Event::fake([BusinessReassignedToWorkspace::class]);

        $returned = $this->reassign($customer, $business, $source);

        $this->assertSame($business->id, $returned->id);
        $this->assertSame($before, $this->snapshot($business));
        Event::assertNotDispatched(BusinessReassignedToWorkspace::class);
    }

    // 6. The target's Business-slot capacity check still runs first
    public function test_a_full_bounded_target_still_refuses_on_business_capacity_before_any_location_work(): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Agency, 5);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);
        $this->addBusiness($customer, $target, 'Already Here');
        $before = $this->snapshot($business);

        try {
            $this->reassign($customer, $business, $target);
            $this->fail('Growth holds one Business.');
        } catch (BusinessSlotLimitExceededException) {
        }

        $this->assertSame($before, $this->snapshot($business));
        $this->assertSame($source->id, (int) $business->fresh()->workspace_id);
        $this->assertNull($this->reassignmentAudit($target));
    }

    // 7. Lock order: every Workspace ascending → Business → its locations
    public function test_opposite_direction_moves_keep_the_ascending_workspace_then_business_then_location_lock_order(): void
    {
        [$customer, $inLow, $low] = $this->locationTenant(WorkspacePlanTier::Agency, 2);
        $high = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);
        $this->assertLessThan($high->id, $low->id);

        $lowToHigh = $this->lockQueries(fn () => $this->reassign($customer, $inLow, $high));

        $inHigh = $inLow->fresh();
        $highToLow = $this->lockQueries(fn () => $this->reassign($customer, $inHigh, $low->fresh()));

        foreach (['low→high (bounded target)' => $lowToHigh, 'high→low (unlimited target)' => $highToLow] as $label => $locks) {
            $this->assertSame('workspaces', $locks[0]['table'], $label);
            $this->assertSame([$low->id], $locks[0]['bindings'], $label);
            $this->assertSame('workspaces', $locks[1]['table'], $label);
            $this->assertSame([$high->id], $locks[1]['bindings'], $label);
            $this->assertSame('businesses', $locks[2]['table'], $label);
            $this->assertSame([$inLow->id], $locks[2]['bindings'], $label);
            $this->assertNotContains('workspaces', array_column(array_slice($locks, 2), 'table'), "{$label}: no Workspace is locked after the Business.");
        }

        $this->assertSame('business_locations', $lowToHigh[3]['table'], 'The bounded target counts the locations under a lock taken after the Business.');
    }

    // The customer surface explains the refusal instead of failing
    public function test_the_customer_reassignment_page_explains_a_refused_move(): void
    {
        [$customer, $business, $source] = $this->locationTenant(WorkspacePlanTier::Core, 3);
        $business = $this->grantComplimentaryLocations($business, 1);
        $target = $this->emptyWorkspace($customer, WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->from(route('customer.workspaces.show', $source->uid))
            ->post(route('customer.workspaces.businesses.reassign', [$source->uid, $business->uid]), ['target_workspace_uid' => $target->uid])
            ->assertRedirect(route('customer.workspaces.show', $source->uid))
            ->assertSessionHas('flash_error', 'This Business has extra locations allocated under its current Workspace, so it cannot be moved to another Workspace yet. Contact support.');

        $this->assertSame($source->id, (int) $business->fresh()->workspace_id);
    }

    // --- helpers ------------------------------------------------------

    private function emptyWorkspace(Customer $owner, WorkspacePlanTier $tier): Workspace
    {
        $workspace = $this->createWorkspace($owner->user, ['name' => 'Target ' . $tier->value]);
        $this->assignTier($workspace, $tier);

        return $workspace->fresh();
    }

    private function reassign(Customer $actor, Business $business, Workspace $target): Business
    {
        return app(WorkspaceManager::class)->reassignBusiness((int) $actor->user_id, $business, $target);
    }

    private function catalogId(WorkspacePlanTier $tier): int
    {
        return (int) DB::table('workspace_plan_catalog')->where('tier', $tier->value)->value('id');
    }

    /**
     * @return array<string, mixed>|null the one business_reassignment audit payload written for $target
     */
    private function reassignmentAudit(Workspace $target): ?array
    {
        $rows = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $target->id)
            ->where('transition_type', 'capacity_grandfathered')
            ->get()
            ->map(fn ($row) => json_decode((string) $row->payload, true))
            ->filter(fn ($payload) => ($payload['source'] ?? null) === 'business_reassignment')
            ->values();

        $this->assertLessThanOrEqual(1, $rows->count());

        if ($rows->isEmpty()) {
            return null;
        }

        // MySQL's JSON column reorders object keys: compare the key set, then
        // hand back the payload in a fixed order for assertSame().
        $payload = $rows->first();
        $expectedOrder = ['source', 'business_id', 'source_workspace_id', 'target_workspace_id', 'target_plan_catalog_id', 'active_locations', 'included', 'from_grandfathered_location_slots', 'to_grandfathered_location_slots'];
        $this->assertEqualsCanonicalizing($expectedOrder, array_keys($payload));

        return array_merge(array_flip($expectedOrder), $payload);
    }

    /**
     * Everything a refused or no-op reassignment must leave untouched.
     */
    private function snapshot(Business $business): array
    {
        $row = DB::table('businesses')->where('id', $business->id)->first();

        return [
            'workspace_id' => (int) $row->workspace_id,
            'additional_location_slots' => (int) $row->additional_location_slots,
            'grandfathered_location_slots' => (int) $row->grandfathered_location_slots,
            'grants' => DB::table('workspace_membership_businesses')->where('business_id', $business->id)->orderBy('id')->pluck('workspace_membership_id')->all(),
            'workspace_transitions' => DB::table('workspace_transitions')->count(),
            'entitlement_transitions' => DB::table('workspace_entitlement_transitions')->count(),
            'locations' => DB::table('business_locations')->where('business_id', $business->id)->orderBy('id')->get(['id', 'lifecycle_state', 'is_primary'])->map(fn ($l) => (array) $l)->all(),
        ];
    }

    /**
     * @return array<int, array{table: string, bindings: array<int, mixed>}> every SELECT … FOR UPDATE, in order
     */
    private function lockQueries(\Closure $callback): array
    {
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains(strtolower($query->sql), 'for update') && preg_match('/from `(\w+)`/i', $query->sql, $m)) {
                $locks[] = ['table' => $m[1], 'bindings' => $query->bindings];
            }
        });

        $callback();

        return $locks;
    }
}
