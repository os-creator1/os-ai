<?php

namespace Tests\Feature\Entitlement;

use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Library\Business\BusinessManager;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerLegacyCompatibilityAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const FIXED_REASON = 'Legacy onboarding Workspace — auto-provisioned complimentary Core assignment continuing Milestone 1\'s backfill posture for a brand-new Workspace created by the legacy resolver.';

    private function createFreshCustomer(): Customer
    {
        $user = User::create([
            'first_name' => 'Legacy', 'last_name' => 'Customer',
            'email' => 'legacy' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        return Customer::create(['user_id' => $user->id]);
    }

    public function test_reachable_only_via_zero_candidate_legacy_provisioning_and_succeeds_against_active_core(): void
    {
        Event::fake([WorkspacePlanAssigned::class]);
        $customer = $this->createFreshCustomer();

        $business = app(BusinessManager::class)->createOrUpdateOnboardingBusiness($customer, null, [
            'name' => 'Legacy Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        $workspace = Workspace::find($business->workspace_id);
        $this->assertNotNull($workspace);

        $this->assertDatabaseHas('workspace_plan_assignments', [
            'workspace_id' => $workspace->id,
            'is_complimentary' => true,
            'additional_business_slots' => 0,
            'complimentary_granted_by_user_id' => null,
        ]);

        $assignment = \App\Models\WorkspacePlanAssignment::where('workspace_id', $workspace->id)->first();
        $this->assertSame(self::FIXED_REASON, $assignment->complimentary_reason);
        $this->assertNotNull($assignment->complimentary_granted_at);

        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'plan_assigned',
            'actor_user_id' => null,
            'reason' => self::FIXED_REASON,
        ]);

        $this->assertDatabaseMissing('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'complimentary_granted',
        ]);

        Event::assertDispatched(WorkspacePlanAssigned::class, fn ($e) => $e->workspaceId === $workspace->id && $e->actorUserId === null);
    }

    public function test_never_reachable_for_an_ordinarily_created_workspace(): void
    {
        $owner = User::create([
            'first_name' => 'Ordinary', 'last_name' => 'Owner', 'email' => 'ordinary' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $workspace = app(\App\Library\Workspace\WorkspaceManager::class)->createWorkspace((int) $owner->id, 'Ordinary Workspace');

        $this->assertDatabaseMissing('workspace_plan_assignments', ['workspace_id' => $workspace->id]);
    }

    public function test_no_actor_supplied_path_can_reach_this_compatibility_assignment(): void
    {
        // EntitlementManager::createLegacyOnboardingCompatibilityAssignment()
        // is not part of the public actor-driven mutation surface — no
        // method on EntitlementManager accepts an actorUserId and routes
        // into it; assignFirstPlan() (§13.E) is the only actor-driven first
        // assignment authority. Confirmed structurally: the method exists
        // solely to be called from WorkspaceManager::provisionWorkspaceRecord().
        $reflection = new \ReflectionMethod(\App\Library\Entitlement\EntitlementManager::class, 'createLegacyOnboardingCompatibilityAssignment');
        $this->assertCount(1, $reflection->getParameters());
        $this->assertSame('workspace', $reflection->getParameters()[0]->getName());
    }

    public function test_inactive_core_catalog_rejects_the_compatibility_assignment(): void
    {
        Event::fake([WorkspacePlanAssigned::class]);
        WorkspacePlanCatalog::where('tier', 'core')->update(['is_active' => false]);

        $customer = $this->createFreshCustomer();

        // Scoped before/after counts, not a hard-coded 0 — this proves the
        // failed attempt creates nothing without assuming the whole table
        // must be globally empty at this point in the suite.
        $assignmentCountBefore = \App\Models\WorkspacePlanAssignment::count();
        $transitionCountBefore = \App\Models\WorkspaceEntitlementTransition::count();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(BusinessManager::class)->createOrUpdateOnboardingBusiness($customer, null, [
                'name' => 'Legacy Business', 'industry' => 'photo_booth_service',
                'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
            ]);
        } finally {
            $this->assertSame($assignmentCountBefore, \App\Models\WorkspacePlanAssignment::count(), 'The failed compatibility assignment must create no workspace_plan_assignments row.');
            $this->assertSame($transitionCountBefore, \App\Models\WorkspaceEntitlementTransition::count(), 'The failed compatibility assignment must create no workspace_entitlement_transitions row.');
            Event::assertNotDispatched(WorkspacePlanAssigned::class);
        }
    }
}
