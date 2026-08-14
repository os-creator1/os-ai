<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Entitlement\BusinessFeatureToggleChanged;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class EntitlementManagerBusinessToggleTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'User', 'last_name' => uniqid(), 'email' => 'user' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    /**
     * @return array{workspace: Workspace, business: Business}
     */
    private function entitledWorkspaceWithBusiness(): array
    {
        $owner = $this->createUser();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture.', true, 0);
        $customer = Customer::create(['user_id' => $owner->id]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        return ['workspace' => $workspace->fresh(), 'business' => $business->fresh()];
    }

    public function test_must_be_currently_entitled_to_create_a_disable_row(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);

        $this->expectException(RuntimeException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, $workspace->owner_user_id);
    }

    public function test_create_remove_only_event_fires_and_no_transition_row_is_written(): void
    {
        Event::fake([BusinessFeatureToggleChanged::class]);
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);

        $this->assertDatabaseHas('business_feature_toggles', ['business_id' => $business->id, 'feature_key' => 'crm']);
        $this->assertDatabaseCount('workspace_entitlement_transitions', 1); // only the plan_assigned fixture row
        Event::assertDispatched(BusinessFeatureToggleChanged::class, fn ($e) => $e->disabled === true);

        app(EntitlementManager::class)->enableBusinessFeature($business->fresh(), PlatformFeature::Crm, $workspace->owner_user_id);

        $this->assertDatabaseMissing('business_feature_toggles', ['business_id' => $business->id, 'feature_key' => 'crm']);
        Event::assertDispatched(BusinessFeatureToggleChanged::class, fn ($e) => $e->disabled === false);
    }

    public function test_owner_or_active_admin_authority_matrix(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();

        // Owner allowed.
        $toggle = app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);
        app(EntitlementManager::class)->enableBusinessFeature($business->fresh(), PlatformFeature::Crm, $workspace->owner_user_id);
        $this->assertNotNull($toggle->id);

        // Active Admin allowed.
        $activeAdmin = $this->createUser();
        WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $activeAdmin->id, 'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => true]);
        app(EntitlementManager::class)->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, $activeAdmin->id);
        app(EntitlementManager::class)->enableBusinessFeature($business->fresh(), PlatformFeature::Crm, $activeAdmin->id);

        // Ordinary Staff denied.
        $staff = $this->createUser();
        WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $staff->id, 'role' => WorkspaceMembershipRole::Staff, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => true]);
        try {
            app(EntitlementManager::class)->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, $staff->id);
            $this->fail('Expected UnauthorizedWorkspaceManagementException for Staff.');
        } catch (UnauthorizedWorkspaceManagementException) {
        }

        // Inactive Admin denied.
        $inactiveAdmin = $this->createUser();
        WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $inactiveAdmin->id, 'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => false]);
        try {
            app(EntitlementManager::class)->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, $inactiveAdmin->id);
            $this->fail('Expected UnauthorizedWorkspaceManagementException for inactive Admin.');
        } catch (UnauthorizedWorkspaceManagementException) {
        }

        // Platform administrator with no Workspace standing denied.
        try {
            app(EntitlementManager::class)->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, $this->createAdmin());
            $this->fail('Expected UnauthorizedWorkspaceManagementException for platform admin with no standing.');
        } catch (UnauthorizedWorkspaceManagementException) {
        }

        $this->assertTrue(true);
    }

    public function test_stale_business_after_reassignment_cannot_disable_using_old_workspace_authority(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->entitledWorkspaceWithBusiness();
        $actor = $this->grantCrossAuthority($oldWorkspace, $newWorkspace);

        app(WorkspaceManager::class)->reassignBusiness($actor, $business, $newWorkspace);

        $this->expectException(BusinessWorkspaceMismatchException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $oldWorkspace->owner_user_id);
    }

    private function grantCrossAuthority(Workspace $oldWorkspace, Workspace $newWorkspace): int
    {
        WorkspaceMembership::create(['workspace_id' => $newWorkspace->id, 'user_id' => $oldWorkspace->owner_user_id, 'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => true]);

        return (int) $oldWorkspace->owner_user_id;
    }

    public function test_stale_business_after_reassignment_cannot_enable_using_old_workspace_authority(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->entitledWorkspaceWithBusiness();
        $actor = $this->grantCrossAuthority($oldWorkspace, $newWorkspace);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $actor);
        app(WorkspaceManager::class)->reassignBusiness($actor, $business->fresh(), $newWorkspace);

        $this->expectException(BusinessWorkspaceMismatchException::class);
        app(EntitlementManager::class)->enableBusinessFeature($business, PlatformFeature::Crm, $oldWorkspace->owner_user_id);
    }

    public function test_freshly_loaded_business_in_new_workspace_succeeds_with_only_new_workspace_authority(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->entitledWorkspaceWithBusiness();
        $actor = $this->grantCrossAuthority($oldWorkspace, $newWorkspace);

        // grantCrossAuthority() grants $oldWorkspace's owner (the actor
        // above) Admin standing in $newWorkspace so the reassignment itself
        // is authorized. That means $oldWorkspace->owner_user_id now DOES
        // have standing in the new Workspace, so it cannot be reused to
        // prove "no standing" — a genuinely separate old-Workspace-only
        // Admin is needed for that half of the proof.
        $oldWorkspaceOnlyAdmin = $this->createUser();
        WorkspaceMembership::create(['workspace_id' => $oldWorkspace->id, 'user_id' => $oldWorkspaceOnlyAdmin->id, 'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => true]);

        app(WorkspaceManager::class)->reassignBusiness($actor, $business, $newWorkspace);
        $fresh = $business->fresh();

        $toggle = app(EntitlementManager::class)->disableBusinessFeature($fresh, PlatformFeature::Crm, $newWorkspace->owner_user_id);

        $this->assertNotNull($toggle->id);

        // An old-Workspace Admin with no standing in the new Workspace is
        // denied even against the freshly-loaded (now new-Workspace) Business.
        try {
            app(EntitlementManager::class)->enableBusinessFeature($fresh, PlatformFeature::Crm, $oldWorkspaceOnlyAdmin->id);
            $this->fail('Expected UnauthorizedWorkspaceManagementException.');
        } catch (UnauthorizedWorkspaceManagementException) {
            $this->assertTrue(true);
        }
    }

    public function test_owner_disable_against_inactive_workspace_receives_inactive_workspace_mutation_exception(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        $workspace->update(['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);
    }

    public function test_active_admin_disable_against_inactive_workspace_receives_the_same_exception(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        $admin = $this->createUser();
        WorkspaceMembership::create(['workspace_id' => $workspace->id, 'user_id' => $admin->id, 'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All, 'is_active' => true]);
        $workspace->update(['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $admin->id);
    }

    public function test_enable_against_inactive_workspace_also_fails(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);
        $workspace->update(['is_active' => false]);

        $this->expectException(InactiveWorkspaceMutationException::class);
        app(EntitlementManager::class)->enableBusinessFeature($business->fresh(), PlatformFeature::Crm, $workspace->owner_user_id);
    }

    public function test_no_toggle_row_is_created_or_deleted_on_inactive_workspace_failure(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        $workspace->update(['is_active' => false]);

        try {
            app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);
        } catch (InactiveWorkspaceMutationException) {
        }

        $this->assertDatabaseMissing('business_feature_toggles', ['business_id' => $business->id]);
    }

    public function test_inactive_state_denial_occurs_before_owner_admin_authority(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        $workspace->update(['is_active' => false]);
        $unrelatedUser = $this->createUser();

        // Even an unrelated user (who would ALSO fail authority) receives
        // the inactive-Workspace exception here since it is checked first.
        $this->expectException(InactiveWorkspaceMutationException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $unrelatedUser->id);
    }

    public function test_stale_business_mismatch_still_wins_over_inactive_workspace_check(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->entitledWorkspaceWithBusiness();
        $actor = $this->grantCrossAuthority($oldWorkspace, $newWorkspace);

        app(WorkspaceManager::class)->reassignBusiness($actor, $business, $newWorkspace);
        $oldWorkspace->update(['is_active' => false]);

        $this->expectException(BusinessWorkspaceMismatchException::class);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $oldWorkspace->owner_user_id);
    }

    public function test_fresh_active_workspace_behavior_is_unaffected(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->entitledWorkspaceWithBusiness();

        $toggle = app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);

        $this->assertNotNull($toggle->id);
    }
}
