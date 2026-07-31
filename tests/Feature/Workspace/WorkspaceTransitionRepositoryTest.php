<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceTransitionType;
use App\Models\WorkspaceTransition;
use App\Repositories\Contracts\WorkspaceTransitionRepository;
use App\Repositories\Eloquent\EloquentWorkspaceTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A tests #15-19: WorkspaceTransition model
 * casts/append-only behavior, and WorkspaceTransitionRepository create()/
 * forWorkspace() behavior.
 */
class WorkspaceTransitionRepositoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_container_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(
            EloquentWorkspaceTransitionRepository::class,
            app(WorkspaceTransitionRepository::class)
        );
    }

    // 15. model casts transition_type to the enum.
    public function test_model_casts_transition_type_to_the_enum(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $transition = app(WorkspaceTransitionRepository::class)->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 1,
            'to_owner_user_id' => 2,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $this->assertSame(WorkspaceTransitionType::OwnershipTransferred, $transition->fresh()->transition_type);
    }

    // 16. model has no updated_at behavior.
    public function test_model_has_no_updated_at_behavior(): void
    {
        $this->assertNull(WorkspaceTransition::UPDATED_AT);

        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $transition = app(WorkspaceTransitionRepository::class)->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 1,
            'to_owner_user_id' => 2,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $this->assertArrayNotHasKey('updated_at', $transition->getAttributes());
    }

    // 17. create() persists both transition types with their type-specific fields.
    public function test_create_persists_ownership_transferred_transition(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $transition = app(WorkspaceTransitionRepository::class)->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 10,
            'from_owner_user_id' => 11,
            'to_owner_user_id' => 12,
            'previous_owner_disposition' => 'convert_to_admin',
        ]);

        $fresh = $transition->fresh();
        $this->assertSame(WorkspaceTransitionType::OwnershipTransferred, $fresh->transition_type);
        $this->assertSame($workspace->id, $fresh->workspace_id);
        $this->assertSame(10, $fresh->actor_user_id);
        $this->assertSame(11, $fresh->from_owner_user_id);
        $this->assertSame(12, $fresh->to_owner_user_id);
        $this->assertSame('convert_to_admin', $fresh->previous_owner_disposition);
        $this->assertNull($fresh->business_id);
        $this->assertNull($fresh->from_workspace_id);
    }

    public function test_create_persists_business_reassigned_transition(): void
    {
        $owner = $this->createCustomer();
        $source = $this->createWorkspace($owner->user);
        $target = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $target->id);

        $transition = app(WorkspaceTransitionRepository::class)->create([
            'workspace_id' => $target->id,
            'transition_type' => WorkspaceTransitionType::BusinessReassigned->value,
            'actor_user_id' => 20,
            'business_id' => $business->id,
            'from_workspace_id' => $source->id,
        ]);

        $fresh = $transition->fresh();
        $this->assertSame(WorkspaceTransitionType::BusinessReassigned, $fresh->transition_type);
        $this->assertSame($target->id, $fresh->workspace_id);
        $this->assertSame($source->id, $fresh->from_workspace_id);
        $this->assertSame($business->id, $fresh->business_id);
        $this->assertNull($fresh->from_owner_user_id);
        $this->assertNull($fresh->to_owner_user_id);
        $this->assertNull($fresh->previous_owner_disposition);
    }

    // 18. forWorkspace() includes both incoming (workspace_id) and outgoing (from_workspace_id) reassignment history.
    public function test_for_workspace_includes_incoming_and_outgoing_reassignment_history(): void
    {
        $owner = $this->createCustomer();
        $workspaceA = $this->createWorkspace($owner->user);
        $workspaceB = $this->createWorkspace($owner->user);
        $workspaceC = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspaceB->id);
        $repository = app(WorkspaceTransitionRepository::class);

        // A -> B: outgoing for A, incoming for B.
        $repository->create([
            'workspace_id' => $workspaceB->id,
            'transition_type' => WorkspaceTransitionType::BusinessReassigned->value,
            'actor_user_id' => 1,
            'business_id' => $business->id,
            'from_workspace_id' => $workspaceA->id,
        ]);

        // Unrelated transition entirely on C.
        $repository->create([
            'workspace_id' => $workspaceC->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 1,
            'to_owner_user_id' => 2,
            'previous_owner_disposition' => 'deactivate',
        ]);

        $forA = $repository->forWorkspace($workspaceA->id);
        $forB = $repository->forWorkspace($workspaceB->id);
        $forC = $repository->forWorkspace($workspaceC->id);

        $this->assertCount(1, $forA);
        $this->assertSame($workspaceA->id, $forA->first()->from_workspace_id);

        $this->assertCount(1, $forB);
        $this->assertSame($workspaceB->id, $forB->first()->workspace_id);

        $this->assertCount(1, $forC);
    }

    // 19. forWorkspace() ordering is created_at ascending, then id ascending.
    public function test_for_workspace_orders_by_created_at_then_id(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $repository = app(WorkspaceTransitionRepository::class);

        $older = $repository->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 1,
            'to_owner_user_id' => 2,
            'previous_owner_disposition' => 'deactivate',
        ]);
        $older->created_at = now()->subMinute();
        $older->save();

        $newerA = $repository->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 2,
            'to_owner_user_id' => 3,
            'previous_owner_disposition' => 'deactivate',
        ]);
        $newerB = $repository->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceTransitionType::OwnershipTransferred->value,
            'actor_user_id' => 1,
            'from_owner_user_id' => 3,
            'to_owner_user_id' => 4,
            'previous_owner_disposition' => 'deactivate',
        ]);
        $newerA->created_at = $newerB->created_at;
        $newerA->save();

        $results = $repository->forWorkspace($workspace->id);

        $this->assertSame(
            [$older->id, $newerA->id, $newerB->id],
            $results->pluck('id')->all()
        );
    }
}
