<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspacePlanStatusChanged;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerChangePlanStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function assignedWorkspace(): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', true, 0);

        return $workspace->fresh();
    }

    public function test_actor_and_reason_are_both_mandatory(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createAdmin(), '');
    }

    /**
     * @dataProvider transitionProvider
     */
    public function test_all_real_status_transitions_succeed(WorkspacePlanAssignmentStatus $from, WorkspacePlanAssignmentStatus $to): void
    {
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();

        if ($from !== WorkspacePlanAssignmentStatus::Active) {
            app(EntitlementManager::class)->changePlanStatus($workspace, $from, $admin, 'Seed to from-status.');
        }

        $updated = app(EntitlementManager::class)->changePlanStatus($workspace, $to, $admin, 'Real transition.');

        $this->assertSame($to, $updated->status);
    }

    public static function transitionProvider(): array
    {
        $active = WorkspacePlanAssignmentStatus::Active;
        $inactive = WorkspacePlanAssignmentStatus::Inactive;
        $suspended = WorkspacePlanAssignmentStatus::Suspended;

        return [
            'active->inactive' => [$active, $inactive],
            'active->suspended' => [$active, $suspended],
            'inactive->active' => [$inactive, $active],
            'inactive->suspended' => [$inactive, $suspended],
            'suspended->active' => [$suspended, $active],
            'suspended->inactive' => [$suspended, $inactive],
        ];
    }

    public function test_same_status_no_op_writes_no_transition_or_event_but_validates_authority_and_reason_first(): void
    {
        Event::fake([WorkspacePlanStatusChanged::class]);
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();

        $updated = app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Active, $admin, 'No-op.');

        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $updated->status);
        Event::assertNotDispatched(WorkspacePlanStatusChanged::class);
    }

    public function test_non_administrator_actor_is_denied(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $this->createNonAdmin(), 'Reason.');
    }

    public function test_non_administrator_requesting_own_current_status_still_receives_authorization_exception(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Active, $this->createNonAdmin(), 'Reason.');
    }
}
