<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMemberInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Business;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMemberInvitation;
use App\Notifications\Workspace\WorkspaceMemberInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Settings → Team → Invite a member, for people with no MotionGrove account yet.
 *
 * The whole class runs with public registration CLOSED (ACCOUNT_CAN_REGISTER=false), so every acceptance below also
 * proves that accepting an invitation does not depend on public signup being open.
 */
class WorkspaceMemberInvitationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        $_SERVER['ACCOUNT_CAN_REGISTER'] = 'false';
        $_ENV['ACCOUNT_CAN_REGISTER'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ACCOUNT_CAN_REGISTER'], $_ENV['ACCOUNT_CAN_REGISTER']);

        parent::tearDown();
    }

    public function test_an_existing_active_account_is_still_added_directly_without_an_invitation(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $mila = $this->customerAccount('mila@example.test');

        $this->invite($workspace, 'mila@example.test')->assertSessionHas('flash_success', 'Member added.');

        $this->assertSame(WorkspaceMembershipRole::Staff, WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->firstOrFail()->role);
        $this->assertSame(0, WorkspaceMemberInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_an_unregistered_email_gets_a_pending_hashed_expiring_invitation_and_nothing_else(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace(['name' => 'Harbor Lane Events']);
        $before = $this->footprint();

        $this->invite($workspace, ' New.Person@Example.test ')->assertSessionHas('flash_success', 'Invitation sent.');

        $invitation = WorkspaceMemberInvitation::firstOrFail();
        $this->assertSame('new.person@example.test', $invitation->email);
        $this->assertSame(WorkspaceMemberInvitationStatus::Pending, $invitation->status);
        $this->assertSame(WorkspaceMembershipRole::Staff, $invitation->role);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 5);
        $this->assertNull(User::where('email', 'new.person@example.test')->first(), 'No account exists until the invitation is accepted.');
        $this->assertSame($before, $this->footprint(), 'No workspace, business, subscription or membership is created by inviting.');

        [$uid, $token] = $this->claimParts($mail = $this->sentMail());
        $this->assertSame($invitation->uid, $uid);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('workspace_member_invitations')->first()), 'The token is stored only as a hash.');
        $this->assertStringContainsString('Harbor Lane Events', $mail->subject);
        $this->assertStringContainsString('MotionGrove', $mail->subject);
        $this->assertStringNotContainsStringIgnoringCase('Business OS', json_encode([$mail->subject, $mail->greeting, $mail->introLines, $mail->outroLines, $mail->actionText]), 'This flow\'s own copy says MotionGrove.');
        $this->assertSame('Accept invitation', $mail->actionText);

        $html = (string) $mail->render();
        $this->assertStringContainsString('MotionGrove', $html);
        $this->assertStringContainsString('Harbor Lane Events', $html);
        $this->assertStringContainsString('Staff', $html);
        $this->assertStringContainsString($mail->actionUrl, str_replace('&amp;', '&', $html));
        $this->assertStringContainsString(now()->addDays(7)->format('F j, Y'), $html, 'The expiry date is shown.');
        $this->assertStringContainsString('MotionGrove', $html);
        $this->assertStringNotContainsStringIgnoringCase('Business OS', $html, 'The header and footer never fall back to the retired name.');
    }

    public function test_the_platform_mail_brand_never_falls_back_to_the_retired_name_and_agency_brands_are_unaffected(): void
    {
        foreach (['AI Business OS', 'ai business os', 'Laravel', '  ', ''] as $unset) {
            config(['app.name' => $unset]);
            $this->assertSame('MotionGrove', \App\Library\Mail\MailBrand::platform()->name, "'$unset'");
            $this->assertStringContainsString('MotionGrove. All rights reserved.', implode(' ', \App\Library\Mail\MailBrand::business('Harbor Lane', null, null)->footerLines));
        }

        config(['app.name' => 'Acme Platform']);
        $this->assertSame('Acme Platform', \App\Library\Mail\MailBrand::platform()->name, 'An owner-configured name is respected.');

        $agency = \App\Library\Mail\MailBrand::agency('Bright Agency', '#336699', null);
        $this->assertSame('Bright Agency', $agency->name);
        $this->assertSame('agency', $agency->kind);
    }

    public function test_viewing_the_team_page_never_creates_or_sends_anything(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'pending@example.test');
        Notification::fake();

        $page = $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent();

        $this->assertStringContainsString('Pending invitations', $page);
        $this->assertStringContainsString('pending@example.test', $page);
        $this->assertStringContainsString('Expires', $page);
        $this->assertSame(1, WorkspaceMemberInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_the_invited_person_creates_a_login_and_joins_with_registration_closed(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'new@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        auth()->logout();
        $before = $this->footprint();

        // Public signup really is closed.
        $this->post(route('register.account.store'), ['first_name' => 'X', 'email' => 'public@example.test', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1']);
        $this->assertNull(User::where('email', 'public@example.test')->first());

        $this->get(route('workspace-invitations.claim', [$uid, $token]))->assertOk()->assertSee('Create my sign-in and join');

        $this->post(route('workspace-invitations.register', [$uid, $token]), [
            'first_name' => 'Nora', 'last_name' => 'Newhire', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1',
        ])->assertRedirect(route('customer.workspaces.index'));

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'The emailed single-use link proved they own the address.');
        $this->assertTrue($user->is_customer);
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->assertTrue(\Illuminate\Support\Facades\Auth::validate(['email' => 'new@example.test', 'password' => 'a-strong-pass-1']), 'They can sign in with the password they chose.');
        $this->actingAs($user);
        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(WorkspaceMembershipRole::Staff, $membership->role);
        $this->assertSame(WorkspaceMemberInvitationStatus::Accepted, WorkspaceMemberInvitation::firstOrFail()->status);

        $after = $this->footprint();
        $this->assertSame($before['workspaces'], $after['workspaces']);
        $this->assertSame($before['businesses'], $after['businesses']);
        $this->assertSame($before['subscriptions'], $after['subscriptions'], 'No subscription or trial is created.');
        $this->assertSame($before['memberships'] + 1, $after['memberships']);

        // They now appear under Members, not Pending invitations.
        $this->actingAs($owner);
        $page = $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pending invitations', $page);
        $this->assertStringContainsString('Nora', $page);
    }

    public function test_an_accepted_member_can_reach_only_the_assigned_business(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $assigned = $this->createBusinessForCustomer($this->customerRowFor($owner)->id, $workspace->id);
        // A Workspace holds exactly one Business, so "any other Business" is one in another Workspace of the same owner.
        $otherWorkspace = $this->createWorkspace($owner, ['name' => 'Other Account']);
        $other = $this->createBusinessForCustomer($this->customerRowFor($owner)->id, $otherWorkspace->id);

        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => 'scoped@example.test', 'role' => 'staff', 'business_access_scope' => 'selected', 'business_uids' => [$assigned->uid],
        ])->assertSessionHas('flash_success', 'Invitation sent.');
        [$uid, $token] = $this->claimParts($this->sentMail());

        // A Business outside this Workspace can never be put on an invitation.
        $this->post(route('customer.workspaces.members.store', $workspace->uid), [
            'member_email' => 'smuggle@example.test', 'role' => 'staff', 'business_access_scope' => 'selected', 'business_uids' => [$other->uid],
        ])->assertNotFound();
        $this->assertNull(WorkspaceMemberInvitation::where('email', 'smuggle@example.test')->first());
        auth()->logout();

        $this->post(route('workspace-invitations.register', [$uid, $token]), ['first_name' => 'Sam', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1']);

        $user = User::where('email', 'scoped@example.test')->firstOrFail();
        $manager = app(WorkspaceManager::class);
        $this->assertTrue($manager->userCanAccessBusiness($user->id, $assigned));
        $this->assertFalse($manager->userCanAccessBusiness($user->id, $other->fresh()), 'Nothing in the owner\'s other Workspaces is granted.');
        $this->assertNull(WorkspaceMembership::where('workspace_id', $otherWorkspace->id)->where('user_id', $user->id)->first());
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, WorkspaceMembership::where('user_id', $user->id)->firstOrFail()->business_access_scope);
    }

    public function test_expired_revoked_and_reused_links_grant_nothing(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $payload = ['first_name' => 'Eve', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'];

        // Expired.
        $this->invite($workspace, 'expired@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        WorkspaceMemberInvitation::where('uid', $uid)->update(['expires_at' => now()->subMinute()]);
        auth()->logout();
        $this->get(route('workspace-invitations.claim', [$uid, $token]))->assertOk()->assertSee('no longer valid');
        $this->post(route('workspace-invitations.register', [$uid, $token]), $payload)->assertOk()->assertSee('no longer valid');
        $this->assertNull(User::where('email', 'expired@example.test')->first());

        // Revoked.
        $this->actingAs($owner);
        $this->invite($workspace, 'revoked@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        $this->post(route('customer.workspaces.invitations.revoke', [$workspace->uid, $uid]))->assertSessionHas('flash_success', 'Invitation revoked.');
        auth()->logout();
        $this->post(route('workspace-invitations.register', [$uid, $token]), $payload)->assertOk()->assertSee('no longer valid');
        $this->assertNull(User::where('email', 'revoked@example.test')->first());

        // Reused.
        $this->actingAs($owner);
        $this->invite($workspace, 'reused@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        auth()->logout();
        $this->post(route('workspace-invitations.register', [$uid, $token]), $payload)->assertRedirect();
        auth()->logout();
        $this->post(route('workspace-invitations.register', [$uid, $token]), $payload)->assertOk()->assertSee('no longer valid');
        $this->get(route('workspace-invitations.claim', [$uid, $token]))->assertOk()->assertSee('no longer valid');
        $this->assertSame(1, User::where('email', 'reused@example.test')->count());

        // A wrong token never works.
        $this->actingAs($owner);
        $this->invite($workspace, 'wrongtoken@example.test');
        [$uid] = $this->claimParts($this->sentMail());
        auth()->logout();
        $this->get(route('workspace-invitations.claim', [$uid, str_repeat('a', 64)]))->assertOk()->assertSee('no longer valid');
    }

    public function test_a_resent_invitation_replaces_the_old_link_and_restarts_the_window(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'resend@example.test');
        [$uid, $oldToken] = $this->claimParts($this->sentMail());
        WorkspaceMemberInvitation::where('uid', $uid)->update(['expires_at' => now()->addHour()]);
        Notification::fake();

        $this->post(route('customer.workspaces.invitations.resend', [$workspace->uid, $uid]))->assertSessionHas('flash_success', 'Invitation resent.');
        [, $newToken] = $this->claimParts($this->sentMail());
        auth()->logout();

        $this->assertNotSame($oldToken, $newToken);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, WorkspaceMemberInvitation::firstOrFail()->expires_at->timestamp, 5);
        $this->get(route('workspace-invitations.claim', [$uid, $oldToken]))->assertOk()->assertSee('no longer valid');
        $this->get(route('workspace-invitations.claim', [$uid, $newToken]))->assertOk()->assertSee('Create my sign-in and join');
    }

    public function test_only_the_invited_email_can_accept_and_a_wrong_account_learns_nothing(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'invited@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        $stranger = $this->customerAccount('stranger@example.test');
        $this->actingAs($stranger);

        $refused = $this->get(route('workspace-invitations.claim', [$uid, $token]))->assertOk()->assertSee('no longer valid')->getContent();
        $this->assertStringNotContainsString('invited@example.test', $refused);
        $this->post(route('workspace-invitations.accept', [$uid, $token]))->assertOk()->assertSee('no longer valid');

        $this->assertNull(WorkspaceMembership::where('user_id', $stranger->id)->first());
        $this->assertSame(WorkspaceMemberInvitationStatus::Pending, WorkspaceMemberInvitation::firstOrFail()->status, 'A wrong-account attempt does not burn the invitation.');

        // A person who registered elsewhere with the invited address signs in and accepts.
        $invitee = $this->customerAccount('invited@example.test');
        $this->actingAs($invitee);
        $this->get(route('workspace-invitations.claim', [$uid, $token]))->assertOk()->assertSee('Accept invitation');
        $this->post(route('workspace-invitations.accept', [$uid, $token]))->assertRedirect(route('customer.workspaces.index'));
        $this->assertNotNull(WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $invitee->id)->first());
        $this->assertSame(1, User::where('email', 'invited@example.test')->count(), 'No second account is made for an address that already has one.');
    }

    public function test_the_register_form_for_an_address_that_already_has_an_account_sends_them_to_sign_in(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'later@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        $this->customerAccount('later@example.test');
        auth()->logout();

        $this->post(route('workspace-invitations.register', [$uid, $token]), ['first_name' => 'L', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'])
            ->assertRedirect(route('login'));
        $this->assertSame(1, User::where('email', 'later@example.test')->count());
        $this->assertSame(0, WorkspaceMembership::where('workspace_id', $workspace->id)->count());
    }

    public function test_admin_invitations_stay_owner_only(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $admin = $this->customerAccount('admin@example.test');
        $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin]);

        // The owner may invite an Admin; the Admin may not (the usual 404), but may invite Staff.
        $this->invite($workspace, 'newadmin@example.test', ['role' => 'admin'])->assertSessionHas('flash_success', 'Invitation sent.');
        $adminInvitation = WorkspaceMemberInvitation::where('email', 'newadmin@example.test')->firstOrFail();

        $this->actingAs($admin);
        $this->invite($workspace, 'sneaky@example.test', ['role' => 'admin'])->assertNotFound();
        $this->post(route('customer.workspaces.invitations.resend', [$workspace->uid, $adminInvitation->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.invitations.revoke', [$workspace->uid, $adminInvitation->uid]))->assertNotFound();
        $this->invite($workspace, 'staffer@example.test')->assertSessionHas('flash_success', 'Invitation sent.');
        $this->assertNull(WorkspaceMemberInvitation::where('email', 'sneaky@example.test')->first());
        $this->assertSame(WorkspaceMemberInvitationStatus::Pending, $adminInvitation->fresh()->status);
    }

    public function test_an_invitation_dies_when_the_inviter_loses_authority_before_acceptance(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $admin = $this->customerAccount('admin@example.test');
        $membership = $this->createMembership($workspace, $admin, ['role' => WorkspaceMembershipRole::Admin]);
        $this->actingAs($admin);
        $this->invite($workspace, 'orphan@example.test');
        [$uid, $token] = $this->claimParts($this->sentMail());
        $membership->update(['is_active' => false]);
        auth()->logout();

        $this->post(route('workspace-invitations.register', [$uid, $token]), ['first_name' => 'O', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1'])
            ->assertOk()->assertSee('no longer valid');

        $this->assertNull(User::where('email', 'orphan@example.test')->first(), 'No account is left behind by a refused invitation.');
        $this->assertSame(WorkspaceMemberInvitationStatus::Revoked, WorkspaceMemberInvitation::firstOrFail()->status);
    }

    public function test_duplicate_invitations_and_memberships_are_prevented(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();

        $this->invite($workspace, 'dup@example.test')->assertSessionHas('flash_success', 'Invitation sent.');
        $this->invite($workspace, 'DUP@example.test')->assertSessionHasErrors('member_email');
        $this->assertSame(1, WorkspaceMemberInvitation::count());

        // The owner's own address and an existing member are refused with the same generic message.
        $this->invite($workspace, $owner->email)->assertSessionHasErrors('member_email');
        $member = $this->customerAccount('member@example.test');
        $this->createMembership($workspace, $member, ['role' => WorkspaceMembershipRole::Staff]);
        $this->invite($workspace, 'member@example.test', ['role' => 'admin'])->assertSessionHasErrors('member_email');
        $this->assertSame(1, WorkspaceMemberInvitation::count());

        // A lapsed invitation does not block a fresh one.
        WorkspaceMemberInvitation::query()->update(['expires_at' => now()->subDay()]);
        $this->invite($workspace, 'dup@example.test')->assertSessionHas('flash_success', 'Invitation sent.');
        $this->assertSame(1, WorkspaceMemberInvitation::where('status', WorkspaceMemberInvitationStatus::Pending->value)->count());
    }

    public function test_a_stranger_cannot_invite_into_or_manage_someone_elses_workspace(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $this->invite($workspace, 'target@example.test');
        $invitation = WorkspaceMemberInvitation::firstOrFail();
        $this->actingAs($this->customerAccount('outsider@example.test'));

        $this->invite($workspace, 'x@example.test')->assertNotFound();
        $this->post(route('customer.workspaces.invitations.revoke', [$workspace->uid, $invitation->uid]))->assertNotFound();
        $this->assertSame(1, WorkspaceMemberInvitation::count());
    }

    // ----------------------------------------------------------------------------------------------------------

    /** @return array{0: User, 1: Workspace} */
    private function ownerAndWorkspace(array $workspace = []): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->actingAs($customer->user);

        return [$customer->user, $this->createWorkspace($customer->user, $workspace)];
    }

    private function invite(Workspace $workspace, string $email, array $overrides = [])
    {
        return $this->post(route('customer.workspaces.members.store', $workspace->uid), array_merge([
            'member_email' => $email, 'role' => 'staff', 'business_access_scope' => 'all',
        ], $overrides));
    }

    private function customerAccount(string $email): User
    {
        $user = User::create([
            'first_name' => 'Existing', 'last_name' => 'Person', 'email' => $email, 'status' => true,
            'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer', 'email_verified_at' => now(),
        ]);
        Customer::create(['user_id' => $user->id, 'permissions' => Customer::customerPermissions()]);

        return $user->fresh();
    }

    private function customerRowFor(User $user): Customer
    {
        return Customer::where('user_id', $user->id)->firstOrFail();
    }

    private function sentMail(): \Illuminate\Notifications\Messages\MailMessage
    {
        $mail = null;
        Notification::assertSentOnDemand(WorkspaceMemberInvitationNotification::class, function ($notification, $channels, $notifiable) use (&$mail) {
            $mail = $notification->toMail($notifiable);

            return true;
        });

        return $mail;
    }

    /** @return array{0: string, 1: string} uid and plaintext token from the emailed link */
    private function claimParts(\Illuminate\Notifications\Messages\MailMessage $mail): array
    {
        $this->assertSame(1, preg_match('#/workspace-invitations/([^/]+)/([^/?]+)$#', $mail->actionUrl, $m));

        return [$m[1], $m[2]];
    }

    /** @return array<string, int> */
    private function footprint(): array
    {
        return [
            'workspaces' => Workspace::count(),
            'businesses' => Business::count(),
            'subscriptions' => PlatformSubscription::count(),
            'memberships' => WorkspaceMembership::count(),
        ];
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }
    }
}
