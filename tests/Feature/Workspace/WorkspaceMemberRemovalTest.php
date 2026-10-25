<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\InactiveWorkspaceMembershipMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceMembershipAlreadyExistsException;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspaceMembershipRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Settings → Team → "Delete member": logical removal, the audit snapshot,
 * authority and tenant isolation, the Members list / count / UI, and safe
 * re-adding of a removed member.
 */
class WorkspaceMemberRemovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function manager(): WorkspaceManager
    {
        return app(WorkspaceManager::class);
    }

    private function verifiedCustomer(): Customer
    {
        foreach (['license' => 'test-license-key', 'custom_script' => ''] as $setting => $value) {
            if (! AppConfig::where('setting', $setting)->exists()) {
                AppConfig::create(['setting' => $setting, 'value' => $value]);
            }
        }
        if (! AppConfig::where('setting', 'customer_permissions')->exists()) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        return $customer;
    }

    private function person(string $first, string $last = 'Person'): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first) . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);
    }

    private function remove(Workspace $workspace, User $target)
    {
        return $this->post(route('customer.workspaces.members.remove', [$workspace->uid, $target->uid]));
    }

    public function test_owner_removes_staff_logically_and_keeps_account_other_workspaces_and_audit(): void
    {
        $owner = $this->verifiedCustomer();
        $this->actingAs($owner->user);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user->id, $workspace->id);
        $staff = $this->person('Stacy');
        $membership = $this->createMembership($workspace, $staff, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $business->id]);
        $otherWorkspace = $this->createWorkspace($this->verifiedCustomer()->user, ['name' => 'Elsewhere']);
        $otherMembership = $this->createMembership($otherWorkspace, $staff);

        $this->remove($workspace, $staff)
            ->assertRedirect(route('customer.workspaces.team.show', $workspace->uid))
            ->assertSessionHas('flash_success', 'Member deleted.');

        $membership->refresh();
        $this->assertFalse($membership->is_active);
        $this->assertNotNull($membership->removed_at);
        $this->assertNotNull(User::find($staff->id));
        $this->assertTrue($otherMembership->fresh()->is_active);
        $this->assertNull($otherMembership->fresh()->removed_at);

        $audit = WorkspaceMembershipRemoval::where('workspace_membership_id', $membership->id)->sole();
        $this->assertSame($owner->user->id, (int) $audit->removed_by_user_id);
        $this->assertSame('staff', $audit->role);
        $this->assertSame('selected', $audit->business_access_scope);
        $this->assertSame([$business->id], $audit->business_ids);
        $this->assertNull($audit->reinstated_at);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }

    public function test_removed_member_loses_access_immediately_and_cannot_use_the_workspace(): void
    {
        $owner = $this->verifiedCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user->id, $workspace->id);
        $staff = $this->verifiedCustomer()->user;
        $this->createMembership($workspace, $staff);
        $this->assertTrue($this->manager()->userCanAccessBusiness($staff->id, $business));

        $this->actingAs($owner->user);
        $this->remove($workspace, $staff)->assertSessionHas('flash_success');

        // The removed person's existing session: the Workspace's pages are now a 404 and the Business is closed to them.
        $this->actingAs($staff);
        $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertNotFound();
        $this->assertFalse($this->manager()->userCanAccessBusiness($staff->id, $business));
    }

    public function test_members_list_hides_removed_members_updates_count_and_renders_delete_ui(): void
    {
        $owner = $this->verifiedCustomer();
        $this->actingAs($owner->user);
        $workspace = $this->createWorkspace($owner->user);
        $keep = $this->person('Keeper');
        $drop = $this->person('Dropped');
        $this->createMembership($workspace, $keep);
        $this->createMembership($workspace, $drop);

        $html = $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('3 people', $html);
        $this->assertStringContainsString('Delete member', $html);
        $this->assertStringNotContainsString('Deactivate', $html);
        $this->assertStringContainsString('Delete this member from Test Workspace? They will immediately lose access. Their personal MotionGrove account will not be deleted, and their access to other Workspaces is unaffected.', $html);
        $this->assertStringContainsString('Manage', $html);
        $this->assertStringContainsString('Save changes', $html);

        $this->remove($workspace, $drop);

        $html = $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('2 people', $html);
        $this->assertStringContainsString('Keeper', $html);
        $this->assertStringNotContainsString('Dropped', $html);
    }

    public function test_admin_can_remove_staff_but_not_admin_and_staff_cannot_remove_anyone(): void
    {
        $owner = $this->verifiedCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $admin = $this->verifiedCustomer()->user;
        $otherAdmin = $this->person('Adele');
        $staff = $this->person('Stan');
        $staffActor = $this->verifiedCustomer()->user;
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin]);
        $this->createMembership($workspace, $otherAdmin, ['role' => WorkspaceMembershipRole::Admin]);
        $staffMembership = $this->createMembership($workspace, $staff);
        $this->createMembership($workspace, $staffActor);

        $this->actingAs($staffActor);
        $this->remove($workspace, $staff)->assertNotFound();

        $this->actingAs($admin);
        $this->remove($workspace, $otherAdmin)->assertNotFound();
        $this->remove($workspace, $admin)->assertNotFound();
        $this->remove($workspace, $staff)->assertSessionHas('flash_success');

        $this->assertNotNull($staffMembership->fresh()->removed_at);
        $this->assertNull(WorkspaceMembership::where('user_id', $otherAdmin->id)->first()->removed_at);
        $this->assertNull(WorkspaceMembership::where('user_id', $admin->id)->first()->removed_at);
    }

    public function test_owner_cannot_be_removed(): void
    {
        $owner = $this->verifiedCustomer();
        $this->actingAs($owner->user);
        $workspace = $this->createWorkspace($owner->user);

        $this->remove($workspace, $owner->user)->assertNotFound();
        $this->assertSame($owner->user->id, (int) $workspace->fresh()->owner_user_id);
    }

    public function test_removal_cannot_cross_workspaces(): void
    {
        $ownerA = $this->verifiedCustomer();
        $ownerB = $this->verifiedCustomer();
        $workspaceA = $this->createWorkspace($ownerA->user, ['name' => 'A']);
        $workspaceB = $this->createWorkspace($ownerB->user, ['name' => 'B']);
        $memberOfB = $this->person('Bee');
        $membershipB = $this->createMembership($workspaceB, $memberOfB);

        $this->actingAs($ownerA->user);
        $this->remove($workspaceA, $memberOfB)->assertNotFound();
        $this->remove($workspaceB, $memberOfB)->assertNotFound();

        $this->assertTrue($membershipB->fresh()->is_active);
        $this->assertNull($membershipB->fresh()->removed_at);
        $this->assertSame(0, WorkspaceMembershipRemoval::count());
    }

    public function test_removed_member_cannot_be_reactivated_and_deactivated_member_still_cannot_be_silently_re_added(): void
    {
        $owner = $this->verifiedCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $removed = $this->createMembership($workspace, $this->person('Rem'));
        $deactivated = $this->createMembership($workspace, $this->person('Dea'));

        $this->manager()->removeMember($owner->user->id, $removed);
        $this->manager()->deactivateMember($owner->user->id, $deactivated);

        try {
            $this->manager()->reactivateMember($owner->user->id, $removed->fresh());
            $this->fail('A removed membership must not be reactivated.');
        } catch (InactiveWorkspaceMembershipMutationException) {
            $this->assertFalse($removed->fresh()->is_active);
        }

        $this->expectException(WorkspaceMembershipAlreadyExistsException::class);
        $this->manager()->addMember($owner->user->id, $workspace, (int) $deactivated->user_id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, LocationAccessScope::All);
    }

    public function test_removed_member_can_be_re_added_with_only_the_newly_granted_access(): void
    {
        $owner = $this->verifiedCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user->id, $workspace->id);
        $person = $this->person('Rita');
        $membership = $this->createMembership($workspace, $person, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $business->id]);

        $this->manager()->removeMember($owner->user->id, $membership);

        $readded = $this->manager()->addMember($owner->user->id, $workspace, $person->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, LocationAccessScope::All);

        $this->assertSame($membership->id, $readded->id, 'The historical membership identity is preserved.');
        $fresh = $membership->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->removed_at);
        $this->assertSame(WorkspaceMembershipRole::Staff, $fresh->role);
        $this->assertSame(WorkspaceBusinessAccessScope::All, $fresh->business_access_scope);
        $this->assertSame(0, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());

        $audit = WorkspaceMembershipRemoval::where('workspace_membership_id', $membership->id)->sole();
        $this->assertSame('admin', $audit->role);
        $this->assertSame([$business->id], $audit->business_ids);
        $this->assertSame($owner->user->id, (int) $audit->reinstated_by_user_id);
        $this->assertNotNull($audit->reinstated_at);
    }

    public function test_re_adding_cannot_bypass_owner_only_admin_or_staff_restrictions(): void
    {
        $owner = $this->verifiedCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $admin = $this->verifiedCustomer()->user;
        $staffActor = $this->verifiedCustomer()->user;
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin]);
        $this->createMembership($workspace, $staffActor);
        $removed = $this->createMembership($workspace, $this->person('Rem'));
        $this->manager()->removeMember($owner->user->id, $removed);

        foreach ([[$admin->id, WorkspaceMembershipRole::Admin], [$staffActor->id, WorkspaceMembershipRole::Staff]] as [$actorId, $role]) {
            try {
                $this->manager()->addMember($actorId, $workspace, (int) $removed->user_id, $role, WorkspaceBusinessAccessScope::All, LocationAccessScope::All);
                $this->fail('Actor without authority re-added a removed member.');
            } catch (UnauthorizedWorkspaceManagementException) {
                $this->assertFalse($removed->fresh()->is_active);
            }
        }

        // An active Admin may re-add Staff.
        $this->manager()->addMember($admin->id, $workspace, (int) $removed->user_id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, LocationAccessScope::All);
        $this->assertTrue($removed->fresh()->is_active);
    }

    public function test_removed_member_is_re_added_through_the_team_form_by_email(): void
    {
        $owner = $this->verifiedCustomer();
        $this->actingAs($owner->user);
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user->id, $workspace->id);
        $person = $this->person('Back');
        $membership = $this->createMembership($workspace, $person);
        $this->remove($workspace, $person);

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => $person->email,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'business_uids' => [$business->uid],
        ])->assertSessionHas('flash_success', 'Member added.');

        $fresh = $membership->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->removed_at);
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $fresh->business_access_scope);
        $this->assertSame(1, WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)->count());
    }
}
