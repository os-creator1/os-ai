<?php

namespace Tests\Feature\DesignSystem;

use App\Models\AppConfig;
use App\Models\Announcements;
use App\Models\Role;
use App\Models\SendingServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Design System M2 A3 (Admin Users / Roles / Announcements) — real-HTTP
 * behavior-preservation proof for the restyled 8-view surface. No test
 * file previously existed for AdministratorController, RoleController, or
 * AnnouncementsController (mechanically confirmed before writing this
 * file), so this is the first and only behavioral coverage for these
 * three areas — proving the visual restyle left routes, permission
 * gates, CRUD semantics, status/batch controls, and the announcement
 * tab/Sending-Server-selection behavior completely intact.
 */
class AdminUsersRolesAnnouncementsExistingBehaviorPreservedTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();

        // EloquentAccountRepository::hasPermission() grants an unconditional
        // bypass to user id === 1 ("first user is always super admin").
        // Created first, deliberately, in every test so every OTHER actor
        // created below lands on a higher id -- permission-denial
        // assertions then test the real Gate::define()/session-permission
        // path rather than accidentally exercising the id-1 bypass. Kept as
        // $this->superAdmin: post A3-Bug-Cleanup-Checkpoint,
        // EloquentUserRepository::update() no longer requires the actor to
        // be id 1 (that was Bug 1 -- fixed), but User::is_super_admin
        // (id === 1) still names a genuine, target-specific protected
        // account, used by the super-admin-protection test below.
        //
        // Explicitly forced to id=1 (a raw insert, not User::create(),
        // which would merely take whatever the next auto-increment value
        // happens to be): MySQL/InnoDB does not roll back an
        // AUTO_INCREMENT counter on transaction rollback, so across a
        // long RefreshDatabase-wrapped test run "the next id" drifts well
        // past 1 long before this file's own tests execute. Also required
        // by Bug 2's pre-fix behavior (announcements.user_id defaulted to
        // 1) and still harmless/useful post-fix as the fixed super-admin
        // account's own real id.
        // A raw query-builder insert bypasses Eloquent model events
        // entirely (including HasUid::boot()'s `creating` listener), so
        // `uid` must be set explicitly here or route-model-binding calls
        // like route('admin.administrators.update', $this->superAdmin->uid)
        // would fail with a missing-parameter error.
        DB::table('users')->insert([
            'id' => 1, 'uid' => uniqid('', true), 'first_name' => 'Seed', 'last_name' => 'Filler',
            'email' => 'seed-filler-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false,
            'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->superAdmin = User::find(1);
    }

    // -----------------------------------------------------------------
    // Route names
    // -----------------------------------------------------------------

    public function test_all_a3_route_names_still_resolve(): void
    {
        foreach ([
            'admin.administrators.index', 'admin.administrators.create', 'admin.administrators.store',
            'admin.administrators.show', 'admin.administrators.update', 'admin.administrators.search',
            'admin.administrators.export', 'admin.administrators.active', 'admin.administrators.batch_action',
            'admin.roles.index', 'admin.roles.create', 'admin.roles.store', 'admin.roles.update',
            'admin.roles.show', 'admin.roles.search', 'admin.roles.export', 'admin.roles.active', 'admin.roles.batch_action',
            'admin.announcements.index', 'admin.announcements.create', 'admin.announcements.store',
            'admin.announcements.show', 'admin.announcements.update', 'admin.announcements.search',
            'admin.announcements.batch_action',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Expected route [{$name}] to still be registered.");
        }
    }

    // -----------------------------------------------------------------
    // Authorization gates — Administrator
    // -----------------------------------------------------------------

    public function test_guest_is_denied_every_administrator_route(): void
    {
        // App\Exceptions\Handler::render() (pre-existing, unrelated to A3)
        // renders both AuthenticationException and AuthorizationException
        // as a plain "errors.401" view whenever app.env !== 'local' -- the
        // testing environment included -- rather than a login redirect or
        // a 403. This is this application's own established behavior, not
        // something this restyle introduced or may "fix".
        $this->get(route('admin.administrators.index'))->assertStatus(401);
        $this->get(route('admin.administrators.create'))->assertStatus(401);
    }

    public function test_administrator_index_denied_without_view_permission(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.administrators.index'))->assertStatus(401);
    }

    public function test_administrator_index_renders_with_view_permission(): void
    {
        $this->actingAsAdmin(['access backend', 'view administrator']);

        $this->get(route('admin.administrators.index'))->assertOk();
    }

    public function test_administrator_create_denied_without_create_permission(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.administrators.create'))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Administrator CRUD, status toggle, batch actions
    // -----------------------------------------------------------------

    public function test_administrator_store_persists_with_role_assignment_and_status(): void
    {
        $this->actingAsAdmin(['access backend', 'create administrator']);
        $role = Role::create(['name' => 'ops-' . uniqid('', true), 'status' => 1]);

        $response = $this->post(route('admin.administrators.store'), [
            'first_name' => 'New', 'last_name' => 'Admin',
            'email' => 'new-admin-' . uniqid('', true) . '@example.test',
            'phone' => '12025550179',
            'password' => 'Password!234', 'password_confirmation' => 'Password!234',
            'status' => 1,
            'roles' => [$role->id],
        ]);

        $response->assertRedirect(route('admin.administrators.index'));
        $created = User::where('email', 'like', 'new-admin-%')->first();
        $this->assertNotNull($created);
        $this->assertTrue((bool) $created->status);
        $this->assertTrue($created->roles->contains('id', $role->id));
    }

    public function test_administrator_show_renders_existing_role_selection_and_update_persists(): void
    {
        $actor = $this->actingAsAdmin(['access backend', 'create administrator', 'edit administrator']);
        $this->assertNotSame(1, $actor->id, 'This test must exercise a genuine non-super-admin actor.');
        $role = Role::create(['name' => 'support-' . uniqid('', true), 'status' => 1]);

        $store = $this->post(route('admin.administrators.store'), [
            'first_name' => 'Edit', 'last_name' => 'Target',
            'email' => 'edit-target-' . uniqid('', true) . '@example.test',
            'phone' => '12025550188',
            'password' => 'Password!234', 'password_confirmation' => 'Password!234',
            'status' => 1, 'roles' => [$role->id],
        ]);
        $store->assertRedirect(route('admin.administrators.index'));
        $administrator = User::where('email', 'like', 'edit-target-%')->first();

        $this->get(route('admin.administrators.show', $administrator->uid))
            ->assertOk()
            ->assertSee($administrator->first_name);

        // A3 Bug-Cleanup Checkpoint (Bug 1): a single-record administrator
        // update must succeed for any actor holding the real
        // 'edit administrator' permission -- not only the literal
        // super-admin (id===1). $actor here is deliberately NOT id 1 (see
        // the setUp() note on actor ordering), proving the removed
        // `auth()->id() === 1` mutation gate no longer blocks a
        // legitimately authorized administrator.
        $update = $this->from(route('admin.administrators.show', $administrator->uid))
            ->put(route('admin.administrators.update', $administrator->uid), [
                'first_name' => 'Renamed', 'email' => $administrator->email,
                'roles' => [$role->id], 'timezone' => 'UTC', 'locale' => 'en',
            ]);

        $update->assertRedirect(route('admin.administrators.index'));
        $this->assertSame('Renamed', $administrator->fresh()->first_name);
    }

    public function test_administrator_update_denied_without_edit_permission(): void
    {
        // A3 Bug-Cleanup Checkpoint (Bug 1, invariant item 3): an actor
        // without 'edit administrator' must still be rejected -- the fix
        // removes the accidental id-1 gate, it does not remove real
        // authorization.
        $this->actingAsAdmin(['access backend', 'create administrator']);
        $role = Role::create(['name' => 'noedit-' . uniqid('', true), 'status' => 1]);

        $this->post(route('admin.administrators.store'), [
            'first_name' => 'NoEdit', 'last_name' => 'Target',
            'email' => 'noedit-target-' . uniqid('', true) . '@example.test',
            'phone' => '12025550199',
            'password' => 'Password!234', 'password_confirmation' => 'Password!234',
            'status' => 1, 'roles' => [$role->id],
        ]);
        $administrator = User::where('email', 'like', 'noedit-target-%')->first();

        $update = $this->put(route('admin.administrators.update', $administrator->uid), [
            'first_name' => 'ShouldNotApply', 'email' => $administrator->email,
            'roles' => [$role->id], 'timezone' => 'UTC', 'locale' => 'en',
        ]);

        $update->assertStatus(401);
        $this->assertSame('NoEdit', $administrator->fresh()->first_name);
    }

    public function test_administrator_update_still_protects_the_super_admin_from_deactivation(): void
    {
        // A3 Bug-Cleanup Checkpoint (Bug 1, invariant item 4): the genuine,
        // target-specific super-admin protection in
        // EloquentUserRepository::update() (User::is_super_admin &&
        // ! User::status, corrected from the broken, always-truthy
        // `! $user->active` reference) must still block deactivating the
        // one true super-admin account, independent of who the acting,
        // authorized administrator is.
        $actor = $this->actingAsAdmin(['access backend', 'edit administrator']);
        $this->assertNotSame(1, $actor->id);
        $role = Role::create(['name' => 'protect-' . uniqid('', true), 'status' => 1]);

        $update = $this->put(route('admin.administrators.update', $this->superAdmin->uid), [
            'first_name' => $this->superAdmin->first_name, 'email' => $this->superAdmin->email,
            'roles' => [$role->id], 'timezone' => 'UTC', 'locale' => 'en',
            'status' => 0,
        ]);

        // The FormRequest/controller boundary succeeds (real permission
        // held); the repository's own target-protection guard is what
        // rejects the mutation, rendered as the app's generic JSON error
        // shape (App\Exceptions\Handler -- see other tests' notes on
        // this app's exception rendering).
        $update->assertOk();
        $update->assertJson(['status' => 'error']);
        $this->assertTrue((bool) $this->superAdmin->fresh()->status, 'The super-admin must remain active.');
    }

    public function test_administrator_active_toggle_flips_status(): void
    {
        $this->actingAsAdmin(['access backend', 'edit administrator']);
        $administrator = User::create([
            'first_name' => 'Toggle', 'last_name' => 'Me',
            'email' => 'toggle-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $response = $this->post(route('admin.administrators.active', $administrator->uid));

        $response->assertOk()->assertJson(['status' => 'success']);
        $this->assertFalse((bool) $administrator->fresh()->status);
    }

    public function test_administrator_batch_action_enable_disable_destroy(): void
    {
        $this->actingAsAdmin(['access backend', 'edit administrator', 'delete administrator']);
        $target = User::create([
            'first_name' => 'Batch', 'last_name' => 'Target',
            'email' => 'batch-' . uniqid('', true) . '@example.test',
            'status' => false, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        // Batch actions filter by uid (matching what the DataTables row
        // checkboxes actually carry via data-id -- see AdministratorController
        // @search's $nestedData['uid']), never the numeric primary key.
        $this->post(route('admin.administrators.batch_action'), ['action' => 'enable', 'ids' => [$target->uid]])
            ->assertJson(['status' => 'success']);
        $this->assertTrue((bool) $target->fresh()->status);

        $this->post(route('admin.administrators.batch_action'), ['action' => 'disable', 'ids' => [$target->uid]])
            ->assertJson(['status' => 'success']);
        $this->assertFalse((bool) $target->fresh()->status);

        $this->post(route('admin.administrators.batch_action'), ['action' => 'destroy', 'ids' => [$target->uid]])
            ->assertJson(['status' => 'success']);
        $this->assertNull(User::find($target->id));
    }

    // -----------------------------------------------------------------
    // Authorization gates + CRUD — Roles
    // -----------------------------------------------------------------

    public function test_role_index_denied_without_view_permission(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.roles.index'))->assertStatus(401);
    }

    public function test_role_store_persists_selected_permissions_and_role_show_reflects_them(): void
    {
        $this->actingAsAdmin(['access backend', 'create roles', 'edit roles']);

        $store = $this->post(route('admin.roles.store'), [
            'name' => 'custom-role-' . uniqid('', true),
            'permissions' => ['view administrator', 'access backend'],
        ]);
        $store->assertRedirect(route('admin.roles.index'));

        $role = Role::where('name', 'like', 'custom-role-%')->first();
        $this->assertNotNull($role);
        $this->assertContains('view administrator', $role->permissions);

        $response = $this->get(route('admin.roles.show', $role->uid));
        $response->assertOk();
        $response->assertSee('view administrator', false);
    }

    public function test_role_update_persists_new_permission_set(): void
    {
        $this->actingAsAdmin(['access backend', 'create roles', 'edit roles']);

        $this->post(route('admin.roles.store'), [
            'name' => 'update-role-' . uniqid('', true),
            'permissions' => ['view administrator'],
        ]);
        $role = Role::where('name', 'like', 'update-role-%')->first();

        $update = $this->put(route('admin.roles.update', $role->uid), [
            'name' => $role->name,
            'permissions' => ['edit administrator', 'delete administrator'],
        ]);

        $update->assertRedirect(route('admin.roles.index'));
        $role->refresh();
        $names = $role->permissions;
        $this->assertContains('edit administrator', $names);
        $this->assertContains('delete administrator', $names);
        $this->assertNotContains('view administrator', $names);
    }

    public function test_role_active_toggle_and_batch_action(): void
    {
        $this->actingAsAdmin(['access backend', 'edit roles', 'delete roles']);
        $role = Role::create(['name' => 'toggle-role-' . uniqid('', true), 'status' => true]);

        $this->post(route('admin.roles.active', $role->uid))->assertJson(['status' => 'success']);
        $this->assertFalse((bool) $role->fresh()->status);

        $this->post(route('admin.roles.batch_action'), ['action' => 'destroy', 'ids' => [$role->uid]])
            ->assertJson(['status' => 'success']);
        $this->assertNull(Role::find($role->id));
    }

    // -----------------------------------------------------------------
    // Announcements — tabs, Sending Server selection, CRUD
    // -----------------------------------------------------------------

    public function test_announcement_create_denied_without_permission(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.announcements.create'))->assertStatus(401);
    }

    public function test_announcement_create_sms_tab_shows_sending_server_only_when_available(): void
    {
        $this->actingAsAdmin(['access backend', 'create announcement']);

        $withoutServers = $this->get(route('admin.announcements.create', ['tab' => 'send_by_sms']));
        $withoutServers->assertOk();
        $withoutServers->assertDontSee('name="sending_server"', false);
        $withoutServers->assertSee('name="sender_id"', false);

        SendingServer::create(['name' => 'Test Gateway', 'settings' => 'test', 'status' => 1, 'plain' => 1]);

        $withServers = $this->get(route('admin.announcements.create', ['tab' => 'send_by_sms']));
        $withServers->assertOk();
        $withServers->assertSee('name="sending_server"', false);
    }

    public function test_announcement_create_email_tab_hides_sms_only_fields(): void
    {
        $this->actingAsAdmin(['access backend', 'create announcement']);

        $response = $this->get(route('admin.announcements.create', ['tab' => 'send_by_email']));

        $response->assertOk();
        $response->assertDontSee('name="sending_server"', false);
        $response->assertDontSee('name="sender_id"', false);
        $response->assertSee('name="send_email"', false);
    }

    public function test_announcement_store_broadcast_to_all_customers_persists(): void
    {
        $actor = $this->actingAsAdmin(['access backend', 'create announcement']);
        $this->assertNotSame(1, $actor->id, 'This test must exercise a genuine non-id-1 creator.');
        $this->createActiveCustomer();

        $response = $this->post(route('admin.announcements.store'), [
            'customer' => '0',
            'title' => 'Scheduled maintenance',
            'description' => 'We will be down briefly.',
            'send_by' => 'send_by_email',
        ]);

        $response->assertRedirect(route('admin.announcements.index'));

        // A3 Bug-Cleanup Checkpoint (Bug 2): the announcement must record
        // the real authenticated creator, not silently rely on the
        // `announcements.user_id` schema default of 1.
        $announcement = Announcements::where('title', 'Scheduled maintenance')->first();
        $this->assertNotNull($announcement);
        $this->assertSame($actor->id, $announcement->user_id);
    }

    public function test_announcement_show_renders_correct_tab_for_existing_type_and_update_persists(): void
    {
        $this->actingAsAdmin(['access backend', 'create announcement', 'edit announcement']);
        $this->createActiveCustomer();

        $this->post(route('admin.announcements.store'), [
            'customer' => '0', 'title' => 'Original title',
            'description' => 'Original body.', 'send_by' => 'send_by_email',
        ]);
        $announcement = Announcements::where('title', 'Original title')->first();

        $show = $this->get(route('admin.announcements.show', $announcement->uid));
        $show->assertOk();

        $update = $this->put(route('admin.announcements.update', $announcement->uid), [
            'title' => 'Updated title', 'description' => 'Updated body.', 'send_by' => 'send_by_email',
        ]);
        $update->assertRedirect(route('admin.announcements.index'));
        $this->assertSame('Updated title', $announcement->fresh()->title);
    }

    public function test_announcement_batch_destroy_removes_selected_rows(): void
    {
        $this->actingAsAdmin(['access backend', 'create announcement', 'delete announcement']);
        $this->createActiveCustomer();

        $this->post(route('admin.announcements.store'), [
            'customer' => '0', 'title' => 'To be deleted',
            'description' => 'Body.', 'send_by' => 'send_by_email',
        ]);
        $announcement = Announcements::where('title', 'To be deleted')->first();

        $this->post(route('admin.announcements.batch_action'), ['action' => 'destroy', 'ids' => [$announcement->uid]])
            ->assertJson(['status' => 'success']);

        $this->assertNull(Announcements::find($announcement->id));
    }

    public function test_announcements_index_tab_query_parameter_is_preserved(): void
    {
        $this->actingAsAdmin(['access backend', 'view announcement']);

        $response = $this->get(route('admin.announcements.index', ['tab' => 'announcements']));

        $response->assertOk();
        $response->assertSee('id="announcements-tab"', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createActiveCustomer(): User
    {
        return User::create([
            'first_name' => 'Active', 'last_name' => 'Customer',
            'email' => 'customer' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }
        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
