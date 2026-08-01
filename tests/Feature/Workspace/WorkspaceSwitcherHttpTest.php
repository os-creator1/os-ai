<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 3 Slice 3A: GET customer.workspaces.index. Population is
 * exactly WorkspaceRepository::allForUser() (§14 of the M3 preflight) —
 * these tests prove the HTTP boundary, the effective-role presentation, and
 * that nothing beyond that read-only switcher exists yet.
 */
class WorkspaceSwitcherHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_guest_is_rejected(): void
    {
        // Mirrors BusinessOnboardingHttpTest's identical boundary proof:
        // Handler::render() renders AuthenticationException as 401 under
        // phpunit's APP_ENV=testing for every customer.php route, not a
        // login redirect.
        $this->get(route('customer.workspaces.index'))->assertUnauthorized();
    }

    public function test_route_exists_as_get_with_expected_name(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.index'));

        $route = Route::getRoutes()->getByName('customer.workspaces.index');

        $this->assertNotNull($route);
        $this->assertSame('workspaces', $route->uri());
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods());
    }

    public function test_owner_sees_their_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Owner Co']);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Owner Co', 'is_active' => true, 'role' => 'Owner'],
        ]);
    }

    public function test_active_admin_membership_sees_workspace_and_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Admin Co']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Admin Co', 'is_active' => true, 'role' => 'Admin'],
        ]);
    }

    public function test_active_staff_membership_sees_workspace_and_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Staff Co']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Staff Co', 'is_active' => true, 'role' => 'Staff'],
        ]);
    }

    public function test_inactive_membership_alone_sees_no_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['is_active' => false]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, []);
    }

    public function test_direct_business_ownership_alone_does_not_expose_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $otherOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($otherOwner);
        // customer.user directly owns a Business inside a Workspace it
        // neither owns nor holds any membership in (§14.1 / §7.2).
        $this->createBusinessForCustomer($customer->user_id, $workspace->id);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, []);
    }

    public function test_unrelated_user_sees_no_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $this->createWorkspace($owner);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, []);
    }

    public function test_inactive_owned_workspace_remains_listed_as_inactive(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Dormant Co', 'is_active' => false]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Dormant Co', 'is_active' => false, 'role' => 'Owner'],
        ]);
        $response->assertSee('Inactive');
    }

    public function test_inactive_workspace_reached_through_active_membership_remains_listed(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Dormant Member Co', 'is_active' => false]);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Dormant Member Co', 'is_active' => false, 'role' => 'Staff'],
        ]);
    }

    public function test_owner_role_wins_over_anomalous_coexisting_membership(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Anomaly Co']);
        // Nothing at the schema level prevents an owner from also holding a
        // membership row (RFC-003 §7.3) — the owner check must still win.
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => 'Anomaly Co', 'is_active' => true, 'role' => 'Owner'],
        ]);
    }

    public function test_duplicate_ownership_and_membership_paths_render_once(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createMembership($workspace, $customer->user, ['is_active' => true]);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, [
            ['uid' => $workspace->uid, 'name' => $workspace->name, 'is_active' => true, 'role' => 'Owner'],
        ]);
    }

    public function test_multiple_workspaces_render_in_ascending_id_order(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $otherOwner = $this->createCustomer()->user;

        $workspaceA = $this->createWorkspace($customer->user, ['name' => 'Alpha Co']);
        $workspaceB = $this->createWorkspace($otherOwner, ['name' => 'Beta Co']);
        $this->createMembership($workspaceB, $customer->user, ['is_active' => true]);
        $workspaceC = $this->createWorkspace($customer->user, ['name' => 'Gamma Co']);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertSame(
            [$workspaceA->uid, $workspaceB->uid, $workspaceC->uid],
            $this->viewData($response)->pluck('uid')->all()
        );
    }

    public function test_users_parent_id_grants_no_switcher_entry(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $parentOwner = $this->createCustomer()->user;
        $this->createWorkspace($parentOwner);

        // users.parent_id is legacy sub-account delegation, unrelated to
        // Workspaces (RFC-003 §6 finding 2, §21.8) — not mass-assignable,
        // set directly.
        $customer->user->parent_id = $parentOwner->id;
        $customer->user->save();

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, []);
    }

    public function test_is_admin_alone_grants_no_customer_workspace_entry(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $unrelatedOwner = $this->createCustomer()->user;
        $this->createWorkspace($unrelatedOwner);

        $customer->user->is_admin = true;
        $customer->user->save();

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        // Platform-admin access is a separate, upstream path (RFC-003 §14
        // path 1) that RFC-003 does not add to, wrap, or duplicate — the
        // customer Workspace switcher never grants extra rows for it.
        $this->assertRowsFor($response, []);
    }

    public function test_response_excludes_internal_identifiers_and_email(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $rows = $this->viewData($response);
        $this->assertCount(1, $rows);
        $this->assertSame(['uid', 'name', 'is_active', 'role'], array_keys($rows->first()));

        // The view receives only the four keys asserted above, so the HTML
        // it renders cannot contain owner_user_id, a membership ID, or a
        // Workspace numeric ID either — only the distinctive email needs a
        // direct HTML check, since it's a value that never enters the
        // presentation row at all.
        $response->assertDontSee($customer->user->email);
    }

    public function test_empty_accessible_set_returns_200_with_empty_state_copy(): void
    {
        $this->actingAsHttpCustomer();

        $response = $this->get(route('customer.workspaces.index'))->assertOk();

        $this->assertRowsFor($response, []);
        $response->assertSee("You don't have access to any Workspaces yet.", false);
    }

    public function test_get_produces_no_database_writes(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Snapshot Co']);
        $this->createMembership($workspace, $customer->user, ['is_active' => true]);

        $tables = ['workspaces', 'workspace_memberships', 'workspace_membership_businesses', 'businesses', 'users', 'customers'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => $this->tableFingerprint($table)]);

        $this->get(route('customer.workspaces.index'))->assertOk();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                $this->tableFingerprint($table),
                "Table [{$table}] changed as a result of a GET request."
            );
        }
    }

    public function test_mutation_verbs_are_not_registered_for_workspaces_route(): void
    {
        $route = Route::getRoutes()->getByName('customer.workspaces.index');

        $this->assertNotNull($route);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertNotContains($verb, $route->methods());
        }
    }

    public function test_no_workspace_member_or_business_route_is_introduced(): void
    {
        // Slice 3B adds exactly customer.workspaces.show (RFC-003-M3-ORCHESTRATOR.md
        // §"The locked Slice 3B contract"); member-list and Business-list
        // routes remain out of scope through Slice 3C.
        $this->assertTrue(Route::has('customer.workspaces.show'));
        $this->assertFalse(Route::has('customer.workspaces.members.index'));
        $this->assertFalse(Route::has('customer.workspaces.businesses.index'));
    }

    private function tableFingerprint(string $table): string
    {
        $rows = DB::table($table)->orderBy('id')->get(['id', 'updated_at']);

        return $rows->map(fn ($row) => "{$row->id}:{$row->updated_at}")->implode('|');
    }

    /**
     * @param  array<int, array{uid: string, name: string, is_active: bool, role: string}>  $expected
     */
    private function assertRowsFor($response, array $expected): void
    {
        $rows = $this->viewData($response)
            ->map(fn (array $row) => [
                'uid' => $row['uid'],
                'name' => $row['name'],
                'is_active' => $row['is_active'],
                'role' => $row['role'],
            ])
            ->values()
            ->all();

        $this->assertSame($expected, $rows);
    }

    private function viewData($response): \Illuminate\Support\Collection
    {
        return collect($response->original->getData()['workspaces']);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }
}
