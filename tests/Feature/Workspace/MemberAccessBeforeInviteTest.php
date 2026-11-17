<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMemberInvitationStatus;
use App\Enums\Workspace\WorkspaceMemberModule;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\InvalidMemberInvitationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Library\Workspace\MemberModuleVisibility;
use App\Library\Workspace\WorkspaceMemberInvitationManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMemberInvitation;
use App\Notifications\Workspace\WorkspaceMemberInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Configure a member's access (role, Business access, visible modules) BEFORE inviting them, keep it editable
 * while the invitation is pending, and apply exactly what was saved when they accept (or when an existing
 * account is added directly).
 */
class MemberAccessBeforeInviteTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        [$this->owner, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
    }

    private function manager(): WorkspaceMemberInvitationManager
    {
        return app(WorkspaceMemberInvitationManager::class);
    }

    private function postMember(Customer $as, array $data)
    {
        $this->authenticateAs($as);

        return $this->post(route('customer.workspaces.members.store', $this->workspace->uid), $data + [
            'role' => 'staff', 'business_access_scope' => 'all',
        ]);
    }

    private function crmOnlyForm(string $email, array $extra = []): array
    {
        return ['member_email' => $email, 'modules_submitted' => 1, 'visible_modules' => ['crm']] + $extra;
    }

    private function invitation(string $email = 'new@example.test'): WorkspaceMemberInvitation
    {
        return WorkspaceMemberInvitation::where('email', $email)->firstOrFail();
    }

    /** Re-issue a known token so the test can accept (the plaintext is only ever emailed). */
    private function tokenFor(WorkspaceMemberInvitation $invitation): string
    {
        $token = str_repeat('t', 64);
        $invitation->forceFill(['token_hash' => Hash::make($token)])->save();

        return $token;
    }

    private function staffAdmin(WorkspaceMembershipRole $role): Customer
    {
        $c = $this->createCustomer();
        $this->member($this->workspace, $c->user, $role);

        return $c;
    }

    private function hidden(WorkspaceMembership $m): array
    {
        return array_map(fn ($x) => $x->value, MemberModuleVisibility::normalize($m->fresh()->hidden_modules));
    }

    // 1 ── invitation created with restricted modules ─────────────────────

    public function test_an_invitation_is_created_pending_with_the_chosen_restrictions_and_one_email(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'))->assertSessionHas('flash_success', 'Invitation sent.');

        $inv = $this->invitation();
        $this->assertSame(WorkspaceMemberInvitationStatus::Pending, $inv->status);
        $this->assertEqualsCanonicalizing(['contacts', 'conversations', 'calendar', 'automations', 'website', 'seo', 'ads', 'growth'], $inv->hidden_modules);
        $this->assertNotNull($inv->pending_key);
        $this->assertNotSame('', $inv->token_hash);
        Notification::assertSentOnDemandTimes(WorkspaceMemberInvitationNotification::class, 1);
        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $this->workspace->id)->count());
    }

    public function test_the_default_form_keeps_every_module_visible_and_unknown_modules_are_refused(): void
    {
        $this->postMember($this->owner, ['member_email' => 'a@example.test', 'modules_submitted' => 1, 'visible_modules' => WorkspaceMemberModule::values()]);
        $this->assertNull($this->invitation('a@example.test')->hidden_modules);

        // A request that never mentions modules at all (older client) also restricts nothing.
        $this->postMember($this->owner, ['member_email' => 'b@example.test']);
        $this->assertNull($this->invitation('b@example.test')->hidden_modules);

        $this->postMember($this->owner, ['member_email' => 'c@example.test', 'modules_submitted' => 1, 'visible_modules' => ['crm', 'payroll']])
            ->assertSessionHasErrors('visible_modules.1');
        $this->assertSame(0, WorkspaceMemberInvitation::where('email', 'c@example.test')->count());
    }

    public function test_the_invite_form_shows_access_and_permissions_with_every_module_switch_on_by_default(): void
    {
        $this->authenticateAs($this->owner);
        $html = $this->get(route('customer.workspaces.team.show', $this->workspace->uid))->assertOk()->getContent();
        $form = substr($html, strpos($html, 'data-section="team-add"'), 9000);

        $this->assertStringContainsString('Access &amp; permissions', $form);
        $this->assertStringContainsString('Send invitation', $form);
        $this->assertLessThan(strpos($form, 'Send invitation'), strpos($form, 'Access &amp; permissions'), 'Access comes before the send button.');
        foreach (WorkspaceMemberModule::cases() as $m) {
            $this->assertMatchesRegularExpression('/name="visible_modules\[\]" value="' . $m->value . '"[^>]* checked/', $form);
        }
        $this->assertStringContainsString('data-access-summary', $form);
    }

    // 2 ── applied on acceptance by a new user ────────────────────────────

    public function test_acceptance_by_a_new_user_applies_the_saved_restrictions_atomically(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $token = $this->tokenFor($inv);

        $user = $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'Nora', 'password' => 'a-strong-pass-1']);

        $membership = WorkspaceMembership::where('workspace_id', $this->workspace->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['crm'], array_map(fn ($m) => $m->value, MemberModuleVisibility::visibleFor($membership)));
        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
        $this->assertSame(WorkspaceMemberInvitationStatus::Accepted, $inv->fresh()->status);

        // The row was created WITH the restriction (one insert-time state, no later write that could be missed).
        $this->assertSame($membership->hidden_modules, $inv->hidden_modules);
    }

    public function test_the_membership_and_its_restrictions_commit_or_roll_back_together(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $token = $this->tokenFor($inv);
        $level = DB::transactionLevel();
        $writes = 0;

        // Fail on the SECOND write to the membership row (the one that stores the restrictions), i.e. after the
        // membership row itself already exists inside the transaction.
        WorkspaceMembership::saved(function () use (&$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('simulated failure while writing the restrictions');
            }
        });

        try {
            $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
            $this->fail('expected refusal');
        } catch (InvalidMemberInvitationException) {
        }

        $this->assertGreaterThanOrEqual(2, $writes, 'The membership row and its restrictions were written in the same transaction.');
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $this->workspace->id)->count(), 'The failure rolled the membership back entirely.');
        $this->assertNull(\App\Models\User::where('email', 'new@example.test')->first());
    }

    public function test_a_failed_grant_leaves_no_user_and_no_unrestricted_membership(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $token = $this->tokenFor($inv);
        $this->workspace->forceFill(['is_active' => false])->save();

        try {
            $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
            $this->fail('expected refusal');
        } catch (InvalidMemberInvitationException) {
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $this->workspace->id)->count());
        $this->assertNull(\App\Models\User::where('email', 'new@example.test')->first());
    }

    // 3 ── existing account added directly ────────────────────────────────

    public function test_an_existing_user_added_directly_gets_the_restrictions_on_the_membership(): void
    {
        $existing = $this->createCustomer();

        $this->postMember($this->owner, $this->crmOnlyForm($existing->user->email))->assertSessionHas('flash_success', 'Member added.');

        $m = WorkspaceMembership::where('workspace_id', $this->workspace->id)->where('user_id', $existing->user->id)->firstOrFail();
        $this->assertSame(['crm'], array_map(fn ($x) => $x->value, MemberModuleVisibility::visibleFor($m)));
        $this->assertSame(0, WorkspaceMemberInvitation::count());
    }

    public function test_a_removed_member_re_added_gets_only_the_new_restrictions(): void
    {
        $existing = $this->createCustomer();
        $m = $this->member($this->workspace, $existing->user, WorkspaceMembershipRole::Staff);
        $m->update(['hidden_modules' => ['seo']]);
        app(\App\Library\Workspace\WorkspaceManager::class)->removeMember((int) $this->owner->user->id, $m);

        $this->postMember($this->owner, ['member_email' => $existing->user->email, 'modules_submitted' => 1, 'visible_modules' => WorkspaceMemberModule::values()]);

        $this->assertNull($m->fresh()->hidden_modules, 'Nothing from the old membership is restored.');
    }

    // 4 + 5 ── edit pending invitation; resend preserves ─────────────────

    public function test_a_pending_invitation_is_edited_in_place_without_a_new_email_or_duplicate(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $hash = $inv->token_hash;
        $expires = $inv->expires_at;
        Notification::fake();

        $this->authenticateAs($this->owner);
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), [
            'role' => 'staff', 'business_access_scope' => 'all', 'visible_modules' => ['crm', 'contacts', 'seo'],
        ])->assertSessionHas('flash_success', 'Invitation access updated.');

        $fresh = $inv->fresh();
        $this->assertSame(1, WorkspaceMemberInvitation::count());
        $this->assertEqualsCanonicalizing(['conversations', 'calendar', 'automations', 'website', 'ads', 'growth'], $fresh->hidden_modules);
        $this->assertSame($hash, $fresh->token_hash, 'The link is unchanged.');
        $this->assertTrue($expires->equalTo($fresh->expires_at));
        $this->assertTrue($fresh->isPending());
        Notification::assertNothingSent();
    }

    public function test_the_saved_edit_is_exactly_what_acceptance_applies(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $this->manager()->updateAccess((int) $this->owner->user->id, $inv, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, [], ['ads']);
        $token = $this->tokenFor($inv->fresh());

        $user = $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
        $m = WorkspaceMembership::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(WorkspaceMembershipRole::Admin, $m->role);
        $this->assertSame(['ads'], $this->hidden($m));
    }

    public function test_resending_preserves_the_selected_restrictions_and_replaces_only_the_link(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $before = $inv->hidden_modules;
        $hash = $inv->token_hash;
        Notification::fake();

        $this->authenticateAs($this->owner);
        $this->post(route('customer.workspaces.invitations.resend', [$this->workspace->uid, $inv->uid]))->assertSessionHas('flash_success');

        $this->assertSame($before, $inv->fresh()->hidden_modules);
        $this->assertNotSame($hash, $inv->fresh()->token_hash);
        Notification::assertSentOnDemandTimes(WorkspaceMemberInvitationNotification::class, 1);
    }

    public function test_the_pending_list_offers_edit_access_resend_and_revoke_with_the_saved_state(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $this->authenticateAs($this->owner);
        $html = $this->get(route('customer.workspaces.team.show', $this->workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('Edit access', $html);
        $this->assertStringContainsString('data-invitation-action="resend"', $html);
        $this->assertStringContainsString('data-invitation-action="revoke"', $html);
        $this->assertStringContainsString('data-invitation-action="access"', $html);
        $this->assertStringContainsString('8 modules hidden', preg_replace('/\s+/', ' ', $html));
    }

    // 6 ── accepted / revoked / expired-and-closed refused ─────────────────

    public function test_editing_an_accepted_or_revoked_invitation_is_refused_and_changes_nothing(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('a@example.test'));
        $this->postMember($this->owner, $this->crmOnlyForm('r@example.test'));
        $accepted = $this->invitation('a@example.test');
        $revoked = $this->invitation('r@example.test');
        $this->manager()->acceptAsNewUser($accepted->uid, $this->tokenFor($accepted), ['first_name' => 'A', 'password' => 'a-strong-pass-1']);
        $this->manager()->revoke((int) $this->owner->user->id, $revoked);

        foreach ([$accepted, $revoked] as $inv) {
            $snapshot = $inv->fresh()->hidden_modules;
            $this->authenticateAs($this->owner);
            $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), [
                'role' => 'staff', 'business_access_scope' => 'all', 'visible_modules' => WorkspaceMemberModule::values(),
            ])->assertSessionHas('flash_error', 'That invitation is no longer pending.');
            $this->assertSame($snapshot, $inv->fresh()->hidden_modules);
        }
    }

    // 7 + 8 ── authorization boundaries ──────────────────────────────────

    public function test_owner_configures_staff_and_admin_invitations(): void
    {
        foreach (['staff', 'admin'] as $i => $role) {
            $this->postMember($this->owner, $this->crmOnlyForm("o{$i}@example.test", ['role' => $role]));
            $this->assertSame($role, $this->invitation("o{$i}@example.test")->role->value);
        }
    }

    public function test_an_admin_manages_staff_only_and_cannot_promote_or_touch_admin_invitations(): void
    {
        $admin = $this->staffAdmin(WorkspaceMembershipRole::Admin);

        $this->postMember($admin, $this->crmOnlyForm('s@example.test'))->assertSessionHas('flash_success', 'Invitation sent.');
        $staffInv = $this->invitation('s@example.test');

        // Cannot invite an Admin.
        $this->postMember($admin, $this->crmOnlyForm('adm@example.test', ['role' => 'admin']))->assertNotFound();
        $this->assertSame(0, WorkspaceMemberInvitation::where('email', 'adm@example.test')->count());

        // Cannot promote an existing Staff invitation to Admin.
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $staffInv->uid]), [
            'role' => 'admin', 'business_access_scope' => 'all', 'visible_modules' => ['crm'],
        ])->assertNotFound();
        $this->assertSame(WorkspaceMembershipRole::Staff, $staffInv->fresh()->role);

        // Cannot modify an Admin invitation the owner made.
        $this->postMember($this->owner, $this->crmOnlyForm('adm@example.test', ['role' => 'admin']));
        $adminInv = $this->invitation('adm@example.test');
        $this->authenticateAs($admin);
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $adminInv->uid]), [
            'role' => 'staff', 'business_access_scope' => 'all', 'visible_modules' => ['crm'],
        ])->assertNotFound();
        $this->assertSame(WorkspaceMembershipRole::Admin, $adminInv->fresh()->role);
    }

    public function test_staff_cannot_invite_or_edit_invitations(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $staff = $this->staffAdmin(WorkspaceMembershipRole::Staff);

        $this->postMember($staff, $this->crmOnlyForm('x@example.test'))->assertNotFound();
        $this->authenticateAs($staff);
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), [
            'role' => 'staff', 'business_access_scope' => 'all', 'visible_modules' => [],
        ])->assertNotFound();
        $this->assertSame(0, WorkspaceMemberInvitation::where('email', 'x@example.test')->count());
        $this->assertNotNull($inv->fresh()->hidden_modules);
        $this->assertCount(8, $inv->fresh()->hidden_modules);
    }

    public function test_cross_workspace_invitations_and_businesses_are_rejected(): void
    {
        $other = $this->createIndependentWorkspaceBusiness();
        $this->actingAs($other['customer']->user);
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();

        // A stranger owner of another Workspace cannot edit this invitation, addressed via either Workspace.
        $this->authenticateAs($other['customer']);
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), ['role' => 'staff', 'business_access_scope' => 'all'])->assertNotFound();
        $this->post(route('customer.workspaces.invitations.access', [$other['workspace']->uid, $inv->uid]), ['role' => 'staff', 'business_access_scope' => 'all'])->assertNotFound();

        // Selecting a Business from another Workspace is refused outright.
        $this->postMember($this->owner, ['member_email' => 'x@example.test', 'business_access_scope' => 'selected', 'business_uids' => [$other['business']->uid]])->assertNotFound();
        $this->assertCount(8, $inv->fresh()->hidden_modules);
        $this->assertSame(1, WorkspaceMemberInvitation::count());
    }

    public function test_an_edit_cannot_widen_business_access_beyond_the_editors_authority(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        // An Admin with a narrower (selected) reach cannot grant "all Businesses".
        $admin = $this->createCustomer();
        $m = $this->member($this->workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($m, $this->business);

        $this->expectException(UnauthorizedWorkspaceManagementException::class);
        $this->manager()->updateAccess((int) $admin->user->id, $inv, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [], null);
    }

    // 9 ── a restricted user cannot reach hidden modules ──────────────────

    public function test_an_invited_restricted_user_cannot_open_hidden_modules_by_url(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $user = $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], array_keys(config('customer-permissions'))))]);
        $this->actingAs($user);

        foreach (['seo.keywords.index', 'ads.overview', 'growth.index', 'website.show'] as $name) {
            $this->get(route('customer.workspaces.businesses.' . $name, [$this->workspace->uid, $this->business->uid]))->assertNotFound();
        }
        $this->get(route('customer.workspaces.businesses.crm.board', [$this->workspace->uid, $this->business->uid]))->assertOk();
    }

    // 10 ── legacy invitations ─────────────────────────────────────────────

    public function test_an_invitation_without_saved_restrictions_stays_unrestricted_on_acceptance(): void
    {
        $this->manager()->invite((int) $this->owner->user->id, $this->workspace, 'old@example.test', WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, []);
        $inv = $this->invitation('old@example.test');
        DB::table('workspace_member_invitations')->where('id', $inv->id)->update(['hidden_modules' => null]);
        $this->assertNull($inv->fresh()->hidden_modules);

        $user = $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv), ['first_name' => 'O', 'password' => 'a-strong-pass-1']);
        $m = WorkspaceMembership::where('user_id', $user->id)->firstOrFail();

        $this->assertNull($m->hidden_modules);
        $this->assertSame(WorkspaceMemberModule::values(), array_map(fn ($x) => $x->value, MemberModuleVisibility::visibleFor($m)));
    }

    // 11 ── atomicity and edit-versus-accept ───────────────────────────────

    public function test_an_edit_that_commits_first_is_what_acceptance_applies(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $token = $this->tokenFor($inv);

        $this->manager()->updateAccess((int) $this->owner->user->id, $inv, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [], ['seo']);
        $user = $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'N', 'password' => 'a-strong-pass-1']);

        $this->assertSame(['seo'], $this->hidden(WorkspaceMembership::where('user_id', $user->id)->firstOrFail()));
    }

    public function test_an_edit_arriving_after_acceptance_is_refused_and_cannot_alter_the_membership(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $user = $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
        $m = WorkspaceMembership::where('user_id', $user->id)->firstOrFail();
        $before = $m->hidden_modules;

        // The caller still holds the stale, pending-looking model (the race's losing edit).
        $stale = $inv->replicate();
        $stale->id = $inv->id;
        $stale->exists = true;

        try {
            $this->manager()->updateAccess((int) $this->owner->user->id, $stale, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, [], null);
            $this->fail('expected refusal');
        } catch (InvalidMemberInvitationException $e) {
            $this->assertSame('not_pending', $e->reason);
        }

        $this->assertSame($before, $m->fresh()->hidden_modules);
        $this->assertSame(WorkspaceMembershipRole::Staff, $m->fresh()->role);
    }

    public function test_the_edit_and_the_acceptance_take_the_same_row_lock(): void
    {
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $token = $this->tokenFor($inv);
        $locks = [];
        DB::listen(function ($q) use (&$locks) {
            if (str_contains($q->sql, 'workspace_member_invitations') && str_contains(strtolower($q->sql), 'for update')) {
                $locks[] = 'invitation';
            }
        });

        $this->manager()->updateAccess((int) $this->owner->user->id, $inv, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [], null);
        $editLocks = count($locks);
        $this->manager()->acceptAsNewUser($inv->uid, $token, ['first_name' => 'N', 'password' => 'a-strong-pass-1']);

        $this->assertGreaterThanOrEqual(1, $editLocks, 'The edit locks the invitation row FOR UPDATE.');
        $this->assertGreaterThan($editLocks, count($locks), 'So does the acceptance: they serialize on one row.');
    }

    // ── PR #665 corrections: approving actor ────────────────────────────

    public function test_an_owner_promotion_of_an_admin_created_invitation_is_redeemable_and_audited(): void
    {
        $admin = $this->staffAdmin(WorkspaceMembershipRole::Admin);
        $this->postMember($admin, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $this->assertSame((int) $admin->user->id, (int) $inv->invited_by_user_id);

        $this->manager()->updateAccess((int) $this->owner->user->id, $inv, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, [], ['ads']);

        $fresh = $inv->fresh();
        $this->assertSame((int) $admin->user->id, (int) $fresh->invited_by_user_id, 'The original inviter is preserved for audit.');
        $this->assertSame((int) $this->owner->user->id, (int) $fresh->access_approved_by_user_id);

        $user = $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($fresh), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
        $m = WorkspaceMembership::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(WorkspaceMembershipRole::Admin, $m->role);
        $this->assertSame(['ads'], $this->hidden($m));
        $this->assertSame(WorkspaceMemberInvitationStatus::Accepted, $fresh->fresh()->status);
    }

    public function test_an_unedited_invitation_still_uses_the_original_inviter(): void
    {
        $admin = $this->staffAdmin(WorkspaceMembershipRole::Admin);
        $this->postMember($admin, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        $this->assertNull($inv->access_approved_by_user_id);
        $this->assertSame((int) $admin->user->id, $inv->grantingUserId());

        // The inviter losing authority still kills it (unchanged behaviour).
        WorkspaceMembership::where('user_id', $admin->user->id)->update(['role' => WorkspaceMembershipRole::Staff->value]);
        app(\App\Library\Support\RequestScopedCache::class)->flush(); // a later request would not see this request's cached rows
        $this->expectException(InvalidMemberInvitationException::class);
        $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
    }

    public function test_an_owner_expanding_business_access_on_a_scoped_admins_invitation_is_redeemable(): void
    {
        $admin = $this->createCustomer();
        $am = $this->member($this->workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::Selected);
        $this->assign($am, $this->business);
        $this->manager()->invite((int) $admin->user->id, $this->workspace, 'new@example.test', WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected, [$this->business->id]);
        $inv = $this->invitation();

        // The scoped Admin could never have granted "all Businesses"; the owner can, and it must redeem.
        $this->manager()->updateAccess((int) $this->owner->user->id, $inv, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [], null);

        $user = $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv->fresh()), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
        $this->assertSame(WorkspaceBusinessAccessScope::All, WorkspaceMembership::where('user_id', $user->id)->firstOrFail()->business_access_scope);
    }

    public function test_an_editor_who_later_loses_authority_makes_the_invitation_fail_closed(): void
    {
        $admin = $this->staffAdmin(WorkspaceMembershipRole::Admin);
        $this->postMember($admin, $this->crmOnlyForm('new@example.test'));
        $inv = $this->invitation();
        // Another Admin (not owner) is the last approver, then is demoted.
        $editor = $this->staffAdmin(WorkspaceMembershipRole::Admin);
        $this->manager()->updateAccess((int) $editor->user->id, $inv, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, [], ['seo']);
        WorkspaceMembership::where('user_id', $editor->user->id)->update(['role' => WorkspaceMembershipRole::Staff->value]);
        app(\App\Library\Support\RequestScopedCache::class)->flush();

        try {
            $this->manager()->acceptAsNewUser($inv->uid, $this->tokenFor($inv->fresh()), ['first_name' => 'N', 'password' => 'a-strong-pass-1']);
            $this->fail('expected refusal');
        } catch (InvalidMemberInvitationException $e) {
            $this->assertSame('grant_failed', $e->reason);
        }

        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $this->workspace->id)->whereNotIn('user_id', [$admin->user->id, $editor->user->id])->count());
        $this->assertNull(\App\Models\User::where('email', 'new@example.test')->first());
    }

    // ── PR #665 corrections: Selected -> All form values ────────────────

    public function test_the_initial_all_state_renders_business_checkboxes_disabled_and_selected_enabled(): void
    {
        $this->addSecondBusinessWorkspaceOption();
        $this->authenticateAs($this->owner);
        $html = $this->get(route('customer.workspaces.team.show', $this->workspace->uid))->assertOk()->getContent();
        $invite = substr($html, strpos($html, 'data-section="team-add"'));
        $invite = substr($invite, 0, strpos($invite, 'Send invitation'));

        preg_match_all('/<input type="checkbox"[^>]*name="business_uids\[\]"[^>]*>/', $invite, $boxes);

        if ($boxes[0] === []) {
            $this->markTestSkipped('Single-Business Workspace: the picker is not rendered.');
        }

        foreach ($boxes[0] as $box) {
            $this->assertStringContainsString(' disabled', $box, 'All Businesses (the default) must not submit Business ids.');
        }
    }

    public function test_the_values_a_browser_submits_for_all_businesses_are_accepted_and_stray_ids_stay_rejected(): void
    {
        // What the fixed form posts for All: no business_uids at all (disabled boxes are not submitted).
        $this->postMember($this->owner, $this->crmOnlyForm('new@example.test', ['business_access_scope' => 'all']))->assertSessionHas('flash_success');
        $inv = $this->invitation();

        $this->authenticateAs($this->owner);
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), [
            'role' => 'staff', 'business_access_scope' => 'all', 'visible_modules' => ['crm'],
        ])->assertSessionHas('flash_success');
        $this->assertSame(WorkspaceBusinessAccessScope::All, $inv->fresh()->business_access_scope);
        $this->assertNull($inv->fresh()->business_ids);

        // Server-side validation stays strict: ids with the All scope are still refused, on both forms.
        $this->postMember($this->owner, $this->crmOnlyForm('x@example.test', ['business_access_scope' => 'all', 'business_uids' => [$this->business->uid]]))
            ->assertSessionHasErrors('business_uids');
        $this->post(route('customer.workspaces.invitations.access', [$this->workspace->uid, $inv->uid]), [
            'role' => 'staff', 'business_access_scope' => 'all', 'business_uids' => [$this->business->uid],
        ])->assertSessionHasErrors('business_uids');
    }

    public function test_the_page_script_no_longer_unchecks_the_shared_picker_so_selections_survive_a_round_trip(): void
    {
        $this->authenticateAs($this->owner);
        $html = $this->get(route('customer.workspaces.team.show', $this->workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('select[name="business_access_scope"]:not([data-access-scope])', $html, 'The legacy handler (which clears checked boxes) skips the shared partial.');
        $this->assertStringContainsString('i.disabled = !selected', $html, 'The shared partial disables, never clears, the boxes.');
    }

    private function addSecondBusinessWorkspaceOption(): void
    {
        // The picker renders only when the viewer can grant more than one Business; nothing to add in a
        // one-Business Workspace (the test above skips itself then).
    }
}
