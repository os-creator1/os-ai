<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Chat F — Customer Account Access Gate foundation.
 *
 * CustomerAccountAccessResolver in isolation: the ONE mapping from
 * WorkspacePlanAssignmentStatus to the access decision every other test in
 * this slice (the gate, the locked screen) relies on being correct.
 */
class CustomerAccountAccessResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function platformAdminId(): int
    {
        return (int) User::create([
            'first_name' => 'Platform', 'last_name' => 'Owner',
            'email' => 'platform-owner-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function workspaceWithStatus(?WorkspacePlanAssignmentStatus $status): Workspace
    {
        $customer = $this->createCustomer();
        $workspace = Workspace::create(['name' => 'Fixture Workspace', 'owner_user_id' => $customer->user_id, 'is_active' => true]);

        if ($status !== null) {
            $admin = $this->platformAdminId();
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Fixture assignment.', true, 0);

            if ($status !== WorkspacePlanAssignmentStatus::Active) {
                app(EntitlementManager::class)->changePlanStatus($workspace, $status, $admin, 'Fixture status change.');
            }
        }

        return $workspace->fresh();
    }

    public function test_null_workspace_is_usable(): void
    {
        $decision = app(CustomerAccountAccessResolver::class)->resolve(null);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
    }

    public function test_unassigned_workspace_is_usable(): void
    {
        $workspace = $this->workspaceWithStatus(null);

        $decision = app(CustomerAccountAccessResolver::class)->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
    }

    public function test_active_workspace_is_usable(): void
    {
        $workspace = $this->workspaceWithStatus(WorkspacePlanAssignmentStatus::Active);

        $decision = app(CustomerAccountAccessResolver::class)->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertFalse($decision->isLocked());
    }

    public function test_inactive_workspace_is_locked_with_a_truthful_billing_recovery_action(): void
    {
        $workspace = $this->workspaceWithStatus(WorkspacePlanAssignmentStatus::Inactive);

        $decision = app(CustomerAccountAccessResolver::class)->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::LockedInactive, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertSame('plan_inactive', $decision->reason);
        $this->assertNotNull($decision->heading);
        $this->assertNotNull($decision->message);
        $this->assertSame('customer.workspaces.plan.show', $decision->recoveryRouteName);
        $this->assertNotNull($decision->recoveryLabel);
    }

    public function test_suspended_workspace_is_locked_with_no_payment_recovery_promise(): void
    {
        $workspace = $this->workspaceWithStatus(WorkspacePlanAssignmentStatus::Suspended);

        $decision = app(CustomerAccountAccessResolver::class)->resolve($workspace);

        $this->assertSame(CustomerAccountAccessState::LockedSuspended, $decision->state);
        $this->assertTrue($decision->isLocked());
        $this->assertSame('plan_suspended', $decision->reason);
        $this->assertNotNull($decision->heading);
        $this->assertNotNull($decision->message);
        // The one invariant this test exists to pin: suspended must never
        // offer the same "pay to fix it" recovery action inactive does.
        $this->assertNull($decision->recoveryRouteName);
        $this->assertNull($decision->recoveryLabel);
        $this->assertStringNotContainsString('reactivat', strtolower((string) $decision->message));
    }
}
