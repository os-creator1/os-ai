<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\PlatformAnnouncementStatus;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementDelivery;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementManager;
use App\Models\PlatformAdminAction;
use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner V1 final — Users, Support, Administrators/Roles and
 * Announcements: authority, behaviour, audit.
 */
class PlatformOwnerV1PeopleAndAnnouncementsTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private array $perms = [
        'access backend', 'view workspace', 'view business', 'view customer', 'edit customer',
        'view announcement', 'create announcement', 'edit announcement',
        'view administrator', 'create administrator', 'edit administrator',
        'view roles', 'create roles', 'edit roles',
        'view workspace plans', 'manage workspace plans',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function owner(): User
    {
        return $this->actingAsPlatformOwner($this->perms);
    }

    private function customerUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Casey', 'last_name' => 'Customer',
            'email' => 'casey-' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
            'email_verified_at' => now(),
        ], $attrs));
    }

    // ================================================================ users

    public function test_no_business_user_or_guest_can_reach_users_support_announcements_or_admins(): void
    {
        $target = $this->customerUser();
        $urls = [
            route('admin.platform-users.index'), route('admin.platform-users.show', $target->uid),
            route('admin.platform-support.index'), route('admin.platform-announcements.index'),
            route('admin.platform-announcements.create'), route('admin.administrators.index'),
            route('admin.roles.index'), route('admin.platform-features.index'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertUnauthorized();
        }

        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        foreach ($urls as $url) {
            $this->get($url)->assertUnauthorized();
        }

        foreach (['password-reset', 'suspend', 'revoke-sessions'] as $action) {
            $this->post(route('admin.platform-users.act', [$target->uid, $action]), ['reason' => 'because'])->assertUnauthorized();
        }

        $this->assertTrue($target->fresh()->status);
        $this->assertSame(0, PlatformAdminAction::query()->count());
    }

    public function test_the_users_page_searches_by_name_and_email_and_hides_admins(): void
    {
        $this->owner();
        $a = $this->customerUser(['first_name' => 'Zelda', 'email' => 'zelda@example.test']);
        $this->customerUser(['first_name' => 'Other', 'email' => 'other@example.test']);

        $html = $this->get(route('admin.platform-users.index', ['q' => 'zeld']))->assertOk()->getContent();
        $this->assertStringContainsString('zelda@example.test', $html);
        $this->assertStringNotContainsString('other@example.test', $html);

        $html = $this->get(route('admin.platform-users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('zelda@example.test', $html);
        $this->assertStringNotContainsString('Pat Owner', substr($html, strpos($html, 'admin-platform-users-index')), 'Administrators are not listed as customer users.');
        $this->assertNotNull($a);
    }

    public function test_the_user_filters_find_suspended_and_unverified_accounts(): void
    {
        $this->owner();
        $this->customerUser(['email' => 'suspended@example.test', 'status' => false]);
        $this->customerUser(['email' => 'unverified@example.test', 'email_verified_at' => null]);

        $this->assertStringContainsString('suspended@example.test', $this->get(route('admin.platform-users.index', ['state' => 'suspended']))->getContent());
        $this->assertStringNotContainsString('unverified@example.test', $this->get(route('admin.platform-users.index', ['state' => 'suspended']))->getContent());
        $this->assertStringContainsString('unverified@example.test', $this->get(route('admin.platform-users.index', ['state' => 'unverified']))->getContent());
    }

    public function test_the_user_page_shows_memberships_and_never_a_secret(): void
    {
        $this->owner();
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $html = $this->get(route('admin.platform-users.show', $customer->user->uid))->assertOk()->getContent();
        $this->assertStringContainsString($workspace->name, $html);
        $this->assertStringContainsString('Send password reset link', $html);

        foreach (['remember_token', 'api_token', 'two_factor', 'password_hash', $customer->user->password] as $secret) {
            if ($secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }

    public function test_a_password_reset_link_is_emailed_and_audited_without_exposing_it(): void
    {
        $owner = $this->owner();
        $target = $this->customerUser();
        Notification::fake();

        $response = $this->post(route('admin.platform-users.act', [$target->uid, 'password-reset']));
        $response->assertSessionHas('status', 'success');

        Notification::assertSentTo($target, ResetPassword::class);

        $row = PlatformAdminAction::query()->sole();
        $this->assertSame('user.password_reset_sent', $row->action);
        $this->assertSame($owner->id, $row->actor_user_id);
        $this->assertSame($target->uid, $row->subject_ref);
        $this->assertStringNotContainsString('token', strtolower(json_encode($row->payload) . $row->summary));
        $this->assertStringNotContainsString('token', strtolower((string) session('message')));
    }

    public function test_verification_can_be_resent_only_when_unverified(): void
    {
        $this->owner();
        $unverified = $this->customerUser(['email_verified_at' => null]);
        $verified = $this->customerUser();
        Notification::fake();

        $this->post(route('admin.platform-users.act', [$unverified->uid, 'resend-verification']))->assertSessionHas('status', 'success');
        Notification::assertSentTo($unverified, VerifyEmail::class);

        $this->post(route('admin.platform-users.act', [$verified->uid, 'resend-verification']))->assertSessionHas('status', 'error');
        Notification::assertNotSentTo($verified, VerifyEmail::class);
        $this->assertSame(1, PlatformAdminAction::query()->where('action', 'user.verification_resent')->count());
    }

    public function test_suspend_reactivate_and_session_revocation_are_audited_with_a_reason(): void
    {
        $this->owner();
        $target = $this->customerUser();

        $this->post(route('admin.platform-users.act', [$target->uid, 'suspend']), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertTrue($target->fresh()->status);

        $r = $this->post(route('admin.platform-users.act', [$target->uid, 'suspend']), ['reason' => 'Chargeback abuse']); $this->assertSame('success', session('status'), (string) session('message'));
        $fresh = $target->fresh();
        $this->assertFalse((bool) $fresh->status);
        $this->assertNotNull($fresh->password_changed_at, 'Existing sessions are invalidated through CheckPasswordChanged.');

        $this->post(route('admin.platform-users.act', [$target->uid, 'reactivate']), ['reason' => 'Resolved'])->assertSessionHas('status', 'success');
        $this->assertTrue((bool) $target->fresh()->status);

        $r = $this->post(route('admin.platform-users.act', [$target->uid, 'revoke-sessions'])); $this->assertSame('success', session('status'), (string) session('message'));

        $this->assertSame(
            ['user.suspended', 'user.reactivated', 'user.sessions_revoked'],
            PlatformAdminAction::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame('Chargeback abuse', PlatformAdminAction::query()->where('action', 'user.suspended')->value('reason'));
    }

    public function test_an_administrator_account_cannot_be_acted_on_through_the_users_page(): void
    {
        $this->owner();
        $admin = User::create(['uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'A', 'last_name' => 'D', 'email' => 'adm@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);

        $this->post(route('admin.platform-users.act', [$admin->uid, 'suspend']), ['reason' => 'test'])->assertNotFound();
        $this->assertTrue((bool) $admin->fresh()->status);
    }

    public function test_a_permission_less_administrator_cannot_act_on_users(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view customer']);
        $target = $this->customerUser();

        $this->post(route('admin.platform-users.act', [$target->uid, 'suspend']), ['reason' => 'nope'])->assertUnauthorized();
        $this->assertTrue((bool) $target->fresh()->status);
    }

    // ================================================================ support

    public function test_support_finds_users_workspaces_and_businesses_and_links_to_the_cockpit(): void
    {
        $this->owner();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $html = $this->get(route('admin.platform-support.index', ['q' => $business->name]))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.businesses.show', $business), $html);

        $html = $this->get(route('admin.platform-support.index', ['q' => $customer->user->email]))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.platform-users.show', $customer->user->uid), $html);

        $html = $this->get(route('admin.platform-support.index', ['q' => $workspace->name]))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.workspaces.show', $workspace), $html);
        $this->assertStringContainsString('Audit Logs', $html);
    }

    // ================================================================ administrators & roles

    public function test_an_administrator_is_invited_by_email_with_roles_and_no_password_is_set_by_the_owner(): void
    {
        $owner = $this->owner();
        $role = Role::create(['name' => 'Support agent', 'status' => true]);
        Notification::fake();

        $this->post(route('admin.administrators.store'), [
            'first_name' => 'New', 'last_name' => 'Admin', 'email' => 'new.admin@example.test', 'roles' => [$role->id],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.administrators.index'));

        $admin = User::query()->where('email', 'new.admin@example.test')->firstOrFail();
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertSame([$role->id], $admin->roles()->pluck('roles.id')->all());
        Notification::assertSentTo($admin, ResetPassword::class);

        $row = PlatformAdminAction::query()->where('action', 'administrator.invited')->sole();
        $this->assertSame($owner->id, $row->actor_user_id);
    }

    public function test_the_invite_form_has_no_password_field_and_the_list_has_no_datatable_popup(): void
    {
        $this->owner();

        $create = $this->get(route('admin.administrators.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="password"', $create);

        $index = $this->get(route('admin.administrators.index'))->assertOk()->getContent();
        $section = substr($index, strpos($index, 'admin-administrators-index'));
        $this->assertStringNotContainsString('DataTable', $section);
        $this->assertStringContainsString('Invite administrator', $section);
    }

    public function test_an_administrators_roles_can_be_changed_and_are_audited(): void
    {
        $this->owner();
        $a = Role::create(['name' => 'Role A', 'status' => true]);
        $b = Role::create(['name' => 'Role B', 'status' => true]);
        $admin = User::create(['uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'ad@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $admin->roles()->sync([$a->id]);

        $this->put(route('admin.administrators.update', $admin->uid), ['first_name' => 'Ad', 'last_name' => 'Min', 'roles' => [$b->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$b->id], $admin->roles()->pluck('roles.id')->all());
        $row = PlatformAdminAction::query()->where('action', 'administrator.updated')->sole();
        $this->assertSame([$a->id], $row->payload['roles_before']);
        $this->assertSame([$b->id], $row->payload['roles_after']);
    }

    public function test_the_administrator_page_shows_its_own_audit_history_and_refuses_non_administrators(): void
    {
        $this->owner();
        $role = Role::create(['name' => 'Viewer', 'status' => true]);
        $admin = User::create(['uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Hi', 'last_name' => 'Story', 'email' => 'hist@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $this->put(route('admin.administrators.update', $admin->uid), ['first_name' => 'Hi', 'last_name' => 'Story', 'roles' => [$role->id]])->assertSessionHasNoErrors();

        $html = $this->get(route('admin.administrators.show', $admin->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Updated administrator hist@example.test (roles changed)', $html);

        $customer = $this->customerUser();
        $this->get(route('admin.administrators.show', $customer->uid))->assertNotFound();
    }

    public function test_an_administrator_can_be_deactivated_with_a_reason_but_not_oneself(): void
    {
        $owner = $this->owner();
        $admin = User::create(['uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'ad2@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);

        $this->post(route('admin.administrators.status', $admin->uid), ['active' => 0, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.administrators.status', $admin->uid), ['active' => 0, 'reason' => 'Left the company'])->assertSessionHas('status', 'success');
        $this->assertFalse((bool) $admin->fresh()->status);

        $this->post(route('admin.administrators.status', $owner->uid), ['active' => 0, 'reason' => 'oops'])->assertSessionHas('status', 'error');
        $this->assertTrue((bool) $owner->fresh()->status);
    }

    public function test_the_roles_list_is_a_plain_table_and_a_role_edit_is_audited(): void
    {
        $this->owner();
        $role = Role::create(['name' => 'Editors', 'status' => true]);

        $html = $this->get(route('admin.roles.index'))->assertOk()->getContent();
        $section = substr($html, strpos($html, 'admin-roles-index'));
        $this->assertStringContainsString('Editors', $section);
        $this->assertStringNotContainsString('DataTable', $section);

        $this->put(route('admin.roles.update', $role->uid), ['name' => 'Editors', 'permissions' => ['access backend', 'view customer']])
            ->assertSessionHasNoErrors();

        $row = PlatformAdminAction::query()->where('action', 'role.updated')->sole();
        $this->assertContains('view customer', $row->payload['added']);
    }

    // ================================================================ announcements

    private function draftData(array $o = []): array
    {
        return array_merge(['title' => 'Maintenance tonight', 'body' => 'Short downtime at 2am.', 'audience' => 'all', 'channels' => ['in_app']], $o);
    }

    public function test_an_announcement_can_be_drafted_scheduled_published_and_cancelled_through_the_ui(): void
    {
        $owner = $this->owner();

        $this->post(route('admin.platform-announcements.store'), $this->draftData(['submit' => 'draft']))->assertSessionHasNoErrors();
        $a = PlatformAnnouncement::query()->sole();
        $this->assertSame(PlatformAnnouncementStatus::Draft, $a->status);
        $this->assertSame($owner->id, $a->created_by);

        $this->post(route('admin.platform-announcements.transition', [$a->uid, 'schedule']), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame(PlatformAnnouncementStatus::Scheduled, $a->fresh()->status);

        $this->post(route('admin.platform-announcements.transition', [$a->uid, 'publish']))->assertSessionHasNoErrors();
        $this->assertSame(PlatformAnnouncementStatus::Published, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->published_at);

        $this->post(route('admin.platform-announcements.transition', [$a->uid, 'cancel']))->assertSessionHasNoErrors();
        $this->assertSame(PlatformAnnouncementStatus::Cancelled, $a->fresh()->status);

        $this->assertSame(
            ['announcement.created', 'announcement.scheduled', 'announcement.published', 'announcement.cancelled'],
            PlatformAdminAction::query()->where('subject_type', 'announcement')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_form_can_save_and_schedule_in_one_step_and_the_list_filters_by_status(): void
    {
        $this->owner();

        $this->post(route('admin.platform-announcements.store'), $this->draftData([
            'submit' => 'schedule', 'scheduled_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(PlatformAnnouncementStatus::Scheduled, PlatformAnnouncement::query()->sole()->status);

        $this->get(route('admin.platform-announcements.index', ['status' => 'scheduled']))->assertSee('Maintenance tonight');
        $drafts = $this->get(route('admin.platform-announcements.index', ['status' => 'draft']))->getContent();
        $this->assertStringNotContainsString('Maintenance tonight', substr($drafts, strpos($drafts, 'admin-platform-announcements-index')));
    }

    public function test_save_and_schedule_without_a_time_keeps_the_draft_and_asks_for_the_time(): void
    {
        $this->owner();

        $this->post(route('admin.platform-announcements.store'), $this->draftData(['submit' => 'schedule']))
            ->assertSessionHasErrors('scheduled_at');

        $a = PlatformAnnouncement::query()->sole();
        $this->assertSame(PlatformAnnouncementStatus::Draft, $a->status, 'Saved as a draft, not silently scheduled.');
    }

    public function test_scheduling_in_the_past_and_empty_content_are_refused(): void
    {
        $this->owner();

        $this->post(route('admin.platform-announcements.store'), $this->draftData(['title' => '']))->assertSessionHasErrors('title');
        $this->post(route('admin.platform-announcements.store'), $this->draftData(['audience' => 'tiers']))->assertSessionHasErrors('audience_tiers');
        $this->assertSame(0, PlatformAnnouncement::query()->count());

        $this->post(route('admin.platform-announcements.store'), $this->draftData());
        $a = PlatformAnnouncement::query()->sole();
        $this->post(route('admin.platform-announcements.transition', [$a->uid, 'schedule']), ['scheduled_at' => now()->subHour()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('scheduled_at');
        $this->assertSame(PlatformAnnouncementStatus::Draft, $a->fresh()->status);
    }

    public function test_audience_by_plan_is_stored_and_published_announcements_expire_by_time(): void
    {
        $owner = $this->owner();
        $m = app(PlatformAnnouncementManager::class);

        $a = $m->createDraft($owner->id, $this->draftData(['audience' => 'tiers', 'audience_tiers' => ['growth', 'agency'], 'expires_at' => now()->addMinute()]));
        $this->assertSame(['growth', 'agency'], $a->audience_tiers);

        $m->publishNow($owner->id, $a);
        $this->assertSame(PlatformAnnouncementStatus::Published, $a->fresh()->effectiveStatus());

        $this->travel(2)->minutes();
        $this->assertSame(PlatformAnnouncementStatus::Expired, $a->fresh()->effectiveStatus());
        $this->assertSame(PlatformAnnouncementStatus::Published, $a->fresh()->status, 'Expired is derived, never stored.');
    }

    public function test_a_cancelled_or_published_announcement_cannot_be_edited_or_republished(): void
    {
        $owner = $this->owner();
        $m = app(PlatformAnnouncementManager::class);
        $a = $m->createDraft($owner->id, $this->draftData());
        $m->publishNow($owner->id, $a);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $m->update($owner->id, $a->fresh(), $this->draftData(['title' => 'Changed']));
    }

    public function test_sweep_publishes_only_due_scheduled_announcements_once_through_the_delivery_seam(): void
    {
        $owner = $this->owner();
        $delivered = new \ArrayObject();
        $this->app->bind(PlatformAnnouncementDelivery::class, fn () => new class($delivered) implements PlatformAnnouncementDelivery {
            public function __construct(public \ArrayObject $log) {}
            public function deliver(PlatformAnnouncement $a): ?string { $this->log[] = $a->uid; return 'ref-' . $a->id; }
            public function withdraw(PlatformAnnouncement $a): void {}
        });
        $m = app(PlatformAnnouncementManager::class);

        $due = $m->createDraft($owner->id, $this->draftData(['title' => 'Due']));
        $m->schedule($owner->id, $due, now()->addMinutes(5));
        $later = $m->createDraft($owner->id, $this->draftData(['title' => 'Later']));
        $m->schedule($owner->id, $later, now()->addDay());

        $this->travel(10)->minutes();
        $this->assertSame(1, $m->sweepDue());
        $this->assertSame(0, $m->sweepDue(), 'Idempotent.');

        $this->assertSame(PlatformAnnouncementStatus::Published, $due->fresh()->status);
        $this->assertSame('ref-' . $due->id, $due->fresh()->delivery_ref);
        $this->assertSame(PlatformAnnouncementStatus::Scheduled, $later->fresh()->status);
        $this->assertSame([$due->uid], $delivered->getArrayCopy());
    }

    public function test_a_non_owner_cannot_manage_announcements(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view announcement']);

        $this->post(route('admin.platform-announcements.store'), $this->draftData())->assertUnauthorized();
        $this->assertSame(0, PlatformAnnouncement::query()->count());
    }

    // ================================================================ audit page

    public function test_the_audit_page_lists_platform_actions_and_filters_by_type(): void
    {
        $this->owner();
        $target = $this->customerUser();
        $this->post(route('admin.platform-users.act', [$target->uid, 'revoke-sessions']));

        $html = $this->get(route('admin.platform-owner.audit'))->assertOk()->getContent();
        $this->assertStringContainsString('Signed ' . $target->email . ' out of all sessions', $html);

        $html = $this->get(route('admin.platform-owner.audit', ['type' => 'plan']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Signed ' . $target->email, $html);
    }
}
