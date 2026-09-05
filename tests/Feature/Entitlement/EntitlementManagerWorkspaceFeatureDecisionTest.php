<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency AI Prospecting foundation — EntitlementManager::decideForWorkspace()
 * is the Business-independent subset of RFC-004 §14's decide() algorithm,
 * added because ProspectOutreach is a Workspace-level feature with no
 * owning Business at all. These tests cover exactly the invariant the
 * foundation task specified: Core denied, Growth denied, Agency allowed,
 * subject to the same suspended/inactive/override rules decide() already
 * enforces for every other feature.
 */
class EntitlementManagerWorkspaceFeatureDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createWorkspace(WorkspacePlanTier $tier): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createAdmin(), 'Fixture assignment.', true, 0);

        return $workspace->fresh();
    }

    public function test_agency_workspace_is_allowed(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Agency);

        $decision = app(EntitlementManager::class)->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_core_workspace_is_denied(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Core);

        $decision = app(EntitlementManager::class)->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('not_entitled_by_plan', $decision->reason);
    }

    public function test_growth_workspace_is_denied(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Growth);

        $decision = app(EntitlementManager::class)->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('not_entitled_by_plan', $decision->reason);
    }

    public function test_suspended_agency_workspace_is_denied(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Agency);
        $manager = app(EntitlementManager::class);
        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Suspend for fixture.');

        $decision = $manager->decideForWorkspace($workspace->fresh(), PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('plan_suspended', $decision->reason);
    }

    public function test_inactive_agency_workspace_is_denied(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Agency);
        $manager = app(EntitlementManager::class);
        $manager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), 'Deactivate for fixture.');

        $decision = $manager->decideForWorkspace($workspace->fresh(), PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('plan_inactive', $decision->reason);
    }

    public function test_workspace_override_deny_is_respected_even_on_agency(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Agency);
        $manager = app(EntitlementManager::class);
        $manager->createOrChangeOverride(
            $workspace,
            PlatformFeature::ProspectOutreach,
            WorkspaceEntitlementOverrideState::Deny,
            $this->createAdmin(),
            'Deny override fixture.'
        );

        $decision = $manager->decideForWorkspace($workspace->fresh(), PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('denied_by_workspace_override', $decision->reason);
    }

    public function test_workspace_override_allow_permits_a_core_workspace(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Core);
        $manager = app(EntitlementManager::class);
        $manager->createOrChangeOverride(
            $workspace,
            PlatformFeature::ProspectOutreach,
            WorkspaceEntitlementOverrideState::Allow,
            $this->createAdmin(),
            'Allow override fixture.'
        );

        $decision = $manager->decideForWorkspace($workspace->fresh(), PlatformFeature::ProspectOutreach->value);

        $this->assertTrue($decision->allowed);
    }

    public function test_unassigned_plan_is_denied(): void
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Unassigned Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        $decision = app(EntitlementManager::class)->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        $this->assertFalse($decision->allowed);
        $this->assertSame('workspace_plan_unassigned', $decision->reason);
    }

    public function test_unknown_feature_key_is_denied(): void
    {
        $workspace = $this->createWorkspace(WorkspacePlanTier::Agency);

        $decision = app(EntitlementManager::class)->decideForWorkspace($workspace, 'not_a_real_feature');

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unknown', $decision->reason);
    }
}
