<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspaceEntitlementOverrideChanged;
use App\Exceptions\Entitlement\UnavailablePlatformFeatureOverrideException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerOverrideTest extends TestCase
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

    public function test_allow_override_for_unavailable_feature_is_rejected(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(UnavailablePlatformFeatureOverrideException::class);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Calendar, WorkspaceEntitlementOverrideState::Allow, $this->createAdmin(), 'Reason.');
    }

    public function test_deny_override_for_unavailable_feature_is_permitted(): void
    {
        $workspace = $this->assignedWorkspace();

        $override = app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Calendar, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), 'Reason.');

        $this->assertSame(WorkspaceEntitlementOverrideState::Deny, $override->state);
    }

    public function test_reason_and_actor_are_required(): void
    {
        $workspace = $this->assignedWorkspace();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), '');
    }

    public function test_override_takes_precedence_over_plan_mapping(): void
    {
        $workspace = $this->assignedWorkspace();
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace(
            \App\Models\Customer::create(['user_id' => $workspace->owner_user_id]),
            $workspace,
            ['name' => 'B1', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
        );

        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), 'Reason.');

        $decision = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertFalse($decision->allowed);
        $this->assertSame('denied_by_workspace_override', $decision->reason);
    }

    public function test_first_time_create_writes_allowed_transition_with_null_from_state(): void
    {
        Event::fake([WorkspaceEntitlementOverrideChanged::class]);
        $workspace = $this->assignedWorkspace();

        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Allow, $this->createAdmin(), 'Reason.');

        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'entitlement_override_allowed',
            'from_override_state' => null,
            'to_override_state' => 'allow',
        ]);
        Event::assertDispatched(WorkspaceEntitlementOverrideChanged::class);
    }

    public function test_changing_an_existing_override_writes_the_correct_before_after_state(): void
    {
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Allow, $admin, 'First.');

        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $admin, 'Change.');

        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'entitlement_override_denied',
            'from_override_state' => 'allow',
            'to_override_state' => 'deny',
        ]);
    }

    public function test_revert_deletes_the_row_and_writes_reverted_transition(): void
    {
        $workspace = $this->assignedWorkspace();
        $admin = $this->createAdmin();
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $admin, 'Reason.');

        app(EntitlementManager::class)->revertOverride($workspace, PlatformFeature::Crm, $admin);

        $this->assertDatabaseMissing('workspace_entitlement_overrides', ['workspace_id' => $workspace->id, 'feature_key' => 'crm']);
        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'entitlement_override_reverted',
            'from_override_state' => 'deny',
            'to_override_state' => null,
        ]);
    }

    public function test_non_administrator_actor_is_denied_for_create_change_and_revert(): void
    {
        $workspace = $this->assignedWorkspace();
        $nonAdmin = $this->createNonAdmin();

        try {
            app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $nonAdmin, 'Reason.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
        }

        try {
            app(EntitlementManager::class)->revertOverride($workspace, PlatformFeature::Crm, $nonAdmin);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertTrue(true);
    }

    public function test_non_administrator_writing_allow_for_unavailable_feature_still_receives_authorization_exception(): void
    {
        $workspace = $this->assignedWorkspace();

        try {
            app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::Calendar, WorkspaceEntitlementOverrideState::Allow, $this->createNonAdmin(), 'Reason.');
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (UnavailablePlatformFeatureOverrideException $e) {
            $this->fail('Expected AuthorizationException, got UnavailablePlatformFeatureOverrideException: ' . $e->getMessage());
        }
    }
}
