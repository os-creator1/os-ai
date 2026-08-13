<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillResult;
use App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1;
use App\Models\Business;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

class WorkspaceEntitlementBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private const COMPLIMENTARY_REASON =
        'Pre-RFC-004 Workspace — grandfathered complimentary assignment during Milestone 1 backfill';

    private function coreCatalogId(): int
    {
        return (int) WorkspacePlanCatalog::where('tier', 'core')->value('id');
    }

    private function addBusinesses(int $workspaceId, int $customerId, int $count, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $business = $this->createBusinessForCustomer($customerId, $workspaceId);

            if ($overrides !== []) {
                $business->fill($overrides);
                $business->save();
            }
        }
    }

    // 1. run with no Workspaces succeeds and returns zero counters.
    public function test_run_with_no_workspaces_succeeds_with_zero_counters(): void
    {
        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(0, $result->workspacesProcessed);
        $this->assertSame(0, $result->assignmentsCreated);
        $this->assertSame(0, $result->remainingUnassignedCount);
    }

    // 2. a Workspace with no Businesses gets Core/active/complimentary with zero additional slots.
    public function test_workspace_with_no_businesses_gets_core_active_complimentary_zero_slots(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(1, $result->workspacesProcessed);
        $this->assertSame(1, $result->assignmentsCreated);

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($this->coreCatalogId(), $assignment->workspace_plan_catalog_id);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active->value, $assignment->status);
        $this->assertSame(1, (int) $assignment->is_complimentary);
        $this->assertSame(0, (int) $assignment->additional_business_slots);
        $this->assertNull($assignment->complimentary_granted_by_user_id);
        $this->assertNotNull($assignment->complimentary_granted_at);
    }

    // 3. the complimentary reason is the exact fixed string.
    public function test_complimentary_reason_is_the_exact_fixed_string(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        (new WorkspaceEntitlementBackfillV1())->run();

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertSame(self::COMPLIMENTARY_REASON, $assignment->complimentary_reason);
    }

    // 4. three or fewer existing Businesses yields zero additional slots.
    public function test_three_or_fewer_businesses_yields_zero_additional_slots(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 3);

        (new WorkspaceEntitlementBackfillV1())->run();

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertSame(0, (int) $assignment->additional_business_slots);
    }

    // 5. exactly four existing Businesses yields one additional slot.
    public function test_four_businesses_yields_one_additional_slot(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 4);

        (new WorkspaceEntitlementBackfillV1())->run();

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertSame(1, (int) $assignment->additional_business_slots);
    }

    // 6. five or more existing Businesses yields two additional slots (capped).
    public function test_five_or_more_businesses_yields_two_additional_slots(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 7);

        (new WorkspaceEntitlementBackfillV1())->run();

        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertSame(2, (int) $assignment->additional_business_slots);
    }

    // 7. a Workspace over capacity keeps every Business and the migration still succeeds.
    public function test_over_capacity_workspace_keeps_every_business_and_succeeds(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 7);

        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(0, $result->remainingUnassignedCount);
        $this->assertSame(7, Business::where('workspace_id', $workspace->id)->count());
    }

    // 8. every Business row is counted regardless of its own status.
    public function test_every_business_counted_regardless_of_status(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 2, ['status' => BusinessStatus::Active]);
        $this->addBusinesses($workspace->id, $customer->user_id, 2, ['status' => BusinessStatus::Inactive]);

        (new WorkspaceEntitlementBackfillV1())->run();

        // 2 active + 2 inactive = 4 total Business rows -> one additional slot.
        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $this->assertSame(1, (int) $assignment->additional_business_slots);
    }

    // 9. exactly one plan_assigned transition row is written per backfilled Workspace.
    public function test_exactly_one_plan_assigned_transition_row_per_workspace(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        (new WorkspaceEntitlementBackfillV1())->run();

        $transitions = DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->get();

        $this->assertCount(1, $transitions);
        $transition = $transitions->first();
        $this->assertSame(WorkspaceEntitlementTransitionType::PlanAssigned->value, $transition->transition_type);
        $this->assertSame($this->coreCatalogId(), $transition->to_plan_catalog_id);
        $this->assertNull($transition->from_plan_catalog_id);
        $this->assertNull($transition->actor_user_id);
        $this->assertSame(self::COMPLIMENTARY_REASON, $transition->reason);
    }

    // 10. an already-assigned Workspace is skipped entirely (idempotent).
    public function test_already_assigned_workspace_is_skipped(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspace->id,
            'workspace_plan_catalog_id' => $this->coreCatalogId(),
            'status' => 'active',
            'is_complimentary' => false,
            'additional_business_slots' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(0, $result->workspacesProcessed);
        $this->assertSame(0, $result->assignmentsCreated);
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->count());
    }

    // 11. a second full run creates no duplicate assignment or transition.
    public function test_second_run_creates_no_duplicate_assignment_or_transition(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $first = (new WorkspaceEntitlementBackfillV1())->run();
        $second = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(1, $first->assignmentsCreated);
        $this->assertSame(0, $second->workspacesProcessed);
        $this->assertSame(0, $second->assignmentsCreated);
        $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->count());
    }

    // 12. multiple Workspaces spanning more than one internal page are all processed.
    public function test_more_workspaces_than_one_page_are_processed_correctly(): void
    {
        $workspaceIds = [];

        for ($i = 0; $i < 5; $i++) {
            $workspaceIds[] = $this->createWorkspace($this->createCustomer()->user)->id;
        }

        $result = (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame(5, $result->workspacesProcessed);
        $this->assertSame(5, $result->assignmentsCreated);
        $this->assertSame(0, $result->remainingUnassignedCount);

        foreach ($workspaceIds as $workspaceId) {
            $this->assertSame(1, DB::table('workspace_plan_assignments')->where('workspace_id', $workspaceId)->count());
        }
    }

    // 13. no legacy plans/subscriptions-family table is touched.
    public function test_no_legacy_plan_subscription_table_touched(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->addBusinesses($workspace->id, $customer->user_id, 1);

        $before = [
            'plans' => DB::table('plans')->count(),
            'subscriptions' => DB::table('subscriptions')->count(),
        ];

        (new WorkspaceEntitlementBackfillV1())->run();

        $after = [
            'plans' => DB::table('plans')->count(),
            'subscriptions' => DB::table('subscriptions')->count(),
        ];

        $this->assertSame($before, $after);
    }

    // 14. no Eloquent model creating/saving events fire during the action.
    public function test_no_eloquent_model_events_fire_during_the_action(): void
    {
        $this->createWorkspace($this->createCustomer()->user);

        $fired = [];
        $recordFiredEvent = function (string $eventName) use (&$fired): void {
            if (str_contains($eventName, 'WorkspacePlanAssignment') || str_contains($eventName, 'WorkspaceEntitlementTransition')) {
                $fired[] = $eventName;
            }
        };

        Event::listen('eloquent.creating: *', $recordFiredEvent);
        Event::listen('eloquent.saving: *', $recordFiredEvent);
        Event::listen('eloquent.created: *', $recordFiredEvent);
        Event::listen('eloquent.saved: *', $recordFiredEvent);

        (new WorkspaceEntitlementBackfillV1())->run();

        $this->assertSame([], $fired);
    }

    // 15. the returned result object is immutable/readonly as designed.
    public function test_result_object_is_readonly(): void
    {
        $reflection = new ReflectionClass(WorkspaceEntitlementBackfillResult::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
