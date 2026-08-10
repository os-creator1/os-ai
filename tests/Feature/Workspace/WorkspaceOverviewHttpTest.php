<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 3 Slice 3B: GET customer.workspaces.show, the locked
 * read-only Workspace overview (docs/automation/RFC-003-M3-ORCHESTRATOR.md,
 * "The locked Slice 3B contract"). Covers route shape, the owner/active-
 * membership 404 boundary, overview presentation, and the embedded
 * owner/Admin-only membership directory.
 */
class WorkspaceOverviewHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_guest_is_rejected(): void
    {
        $this->get(route('customer.workspaces.show', ['workspaceUid' => 'anything']))->assertUnauthorized();
    }

    public function test_route_exists_as_get_with_expected_name_and_uri(): void
    {
        $this->assertTrue(Route::has('customer.workspaces.show'));

        $route = Route::getRoutes()->getByName('customer.workspaces.show');

        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}', $route->uri());
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods());
    }

    public function test_mutation_verbs_are_not_registered_for_workspace_show_route(): void
    {
        $route = Route::getRoutes()->getByName('customer.workspaces.show');

        $this->assertNotNull($route);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertNotContains($verb, $route->methods());
        }
    }

    public function test_no_member_or_business_list_route_is_introduced(): void
    {
        $this->assertFalse(Route::has('customer.workspaces.members.index'));
        $this->assertFalse(Route::has('customer.workspaces.businesses.index'));
    }

    public function test_overview_links_back_to_the_workspace_index(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $response->assertSee('href="' . route('customer.workspaces.index') . '"', false);
    }

    public function test_unknown_uid_returns_not_found(): void
    {
        $this->actingAsHttpCustomer();

        $this->get(route('customer.workspaces.show', ['workspaceUid' => 'does-not-exist']))->assertNotFound();
    }

    public function test_unrelated_user_receives_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertNotFound();

        $this->assertTrue($customer->exists);
    }

    public function test_inactive_membership_alone_receives_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, ['is_active' => false]);

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertNotFound();
    }

    public function test_direct_business_ownership_alone_receives_not_found(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $otherOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($otherOwner);
        $this->createBusinessForCustomer($customer->user_id, $workspace->id);

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertNotFound();
    }

    public function test_users_parent_id_grants_no_overview_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $parentOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($parentOwner);

        $customer->user->parent_id = $parentOwner->id;
        $customer->user->save();

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertNotFound();
    }

    public function test_is_admin_alone_grants_no_overview_access(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $unrelatedOwner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($unrelatedOwner);

        $customer->user->is_admin = true;
        $customer->user->save();

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertNotFound();
    }

    public function test_owner_sees_overview_with_owner_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Owner Co']);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['name' => 'Owner Co', 'is_active' => true, 'role' => 'Owner'],
            $this->workspaceViewData($response)
        );
    }

    public function test_active_admin_sees_overview_with_admin_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Admin Co']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['name' => 'Admin Co', 'is_active' => true, 'role' => 'Admin'],
            $this->workspaceViewData($response)
        );
    }

    public function test_active_staff_sees_overview_with_staff_role(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Staff Co']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['name' => 'Staff Co', 'is_active' => true, 'role' => 'Staff'],
            $this->workspaceViewData($response)
        );
    }

    public function test_owner_role_wins_over_anomalous_coexisting_membership(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Anomaly Co']);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame('Owner', $this->workspaceViewData($response)['role']);
    }

    public function test_inactive_workspace_stays_addressable_to_its_owner(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Dormant Co', 'is_active' => false]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['name' => 'Dormant Co', 'is_active' => false, 'role' => 'Owner'],
            $this->workspaceViewData($response)
        );
        $response->assertSee('Inactive');
    }

    public function test_inactive_workspace_stays_addressable_to_an_active_member(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner, ['name' => 'Dormant Member Co', 'is_active' => false]);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['name' => 'Dormant Member Co', 'is_active' => false, 'role' => 'Staff'],
            $this->workspaceViewData($response)
        );
    }

    public function test_owner_sees_membership_directory(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedMember($workspace, 'Ada', 'Admin', [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertNotNull($this->directoryViewData($response));
        $this->assertCount(1, $this->directoryViewData($response));
    }

    public function test_active_admin_sees_membership_directory(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertNotNull($this->directoryViewData($response));
    }

    public function test_active_staff_never_receives_directory_data(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);
        $admin = $this->createNamedMember($workspace, 'Ada', 'Ledger', [
            'role' => WorkspaceMembershipRole::Admin,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertArrayNotHasKey('directory', $response->original->getData());
        $this->assertArrayNotHasKey('manageableBusinesses', $response->original->getData());
        $response->assertDontSee('Ada');
        $response->assertDontSee($admin->user->email);
    }

    public function test_directory_row_exposes_exactly_the_permitted_fields(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $member = $this->createNamedMember($workspace, 'Priya', 'Shah', [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $businessA = $this->createBusinessForCustomer($member->user->id, $workspace->id);
        $businessB = $this->createBusinessForCustomer($member->user->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $member->id, 'business_id' => $businessA->id]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $member->id, 'business_id' => $businessB->id]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $rows = $this->directoryViewData($response);
        $this->assertCount(1, $rows);
        $this->assertSame(['uid', 'name', 'role', 'scope', 'assigned_business_count', 'assigned_business_uids', 'is_active'], array_keys($rows[0]));
        $this->assertSame([
            'uid' => $member->user->uid,
            'name' => 'Priya Shah',
            'role' => 'Admin',
            'scope' => 'Selected Businesses',
            'assigned_business_count' => 2,
            'assigned_business_uids' => [$businessA->uid, $businessB->uid],
            'is_active' => true,
        ], $rows[0]);
        $response->assertDontSee($member->user->email);
    }

    public function test_directory_reports_the_real_assignment_count_not_an_assumed_one(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        // An All Businesses membership normally has zero rows in
        // workspace_membership_businesses, but the contract requires the
        // real row count to be reported, never assumed from the scope
        // label -- so an anomalous grant row must still surface here.
        $member = $this->createNamedMember($workspace, 'Uma', 'Nair', [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);
        $business = $this->createBusinessForCustomer($member->user->id, $workspace->id);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $member->id, 'business_id' => $business->id]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $rows = $this->directoryViewData($response);
        $this->assertSame('All Businesses', $rows[0]['scope']);
        $this->assertSame(1, $rows[0]['assigned_business_count']);
    }

    public function test_directory_omits_the_owner_row_but_includes_inactive_memberships(): void
    {
        // RFC-003 Milestone 4 Slice 4B: inactive members must remain
        // visible (with is_active === false) so an owner/active-Admin
        // manager can find and reactivate them -- there is no separate
        // members-index surface. The owner itself is still never
        // synthesized as a row.
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedMember($workspace, 'Active', 'Member', ['is_active' => true]);
        $this->createNamedMember($workspace, 'Inactive', 'Member', ['is_active' => false]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $rows = $this->directoryViewData($response);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Active Member', 'Inactive Member'], array_column($rows, 'name'));
        $activeRow = collect($rows)->firstWhere('name', 'Active Member');
        $inactiveRow = collect($rows)->firstWhere('name', 'Inactive Member');
        $this->assertTrue($activeRow['is_active']);
        $this->assertFalse($inactiveRow['is_active']);
    }

    public function test_directory_rows_are_ordered_by_membership_id_ascending(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedMember($workspace, 'Alpha', 'One');
        $this->createNamedMember($workspace, 'Bravo', 'Two');
        $this->createNamedMember($workspace, 'Charlie', 'Three');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['Alpha One', 'Bravo Two', 'Charlie Three'],
            array_column($this->directoryViewData($response), 'name')
        );
    }

    public function test_empty_directory_still_renders_for_an_owner_with_no_members(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame([], $this->directoryViewData($response));
    }

    public function test_response_excludes_forbidden_workspace_and_owner_identifiers(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $data = $response->original->getData();
        $this->assertSame(['workspace', 'businesses', 'directory', 'manageableBusinesses'], array_keys($data));
        $this->assertSame(['name', 'is_active', 'role'], array_keys($data['workspace']));
        $response->assertDontSee($customer->user->email);
    }

    public function test_get_produces_no_database_writes(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Snapshot Co']);
        $this->createNamedMember($workspace, 'Static', 'Member');

        $tables = ['workspaces', 'workspace_memberships', 'workspace_membership_businesses', 'businesses', 'users', 'customers'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => $this->tableFingerprint($table)]);

        $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                $this->tableFingerprint($table),
                "Table [{$table}] changed as a result of a GET request."
            );
        }
    }

    private function tableFingerprint(string $table): string
    {
        $rows = DB::table($table)->orderBy('id')->get(['id', 'updated_at']);

        return $rows->map(fn ($row) => "{$row->id}:{$row->updated_at}")->implode('|');
    }

    /**
     * @return array{name: string, is_active: bool, role: string}
     */
    private function workspaceViewData($response): array
    {
        return $response->original->getData()['workspace'];
    }

    /**
     * @return array<int, array{name: string, role: string, scope: string, assigned_business_count: int}>|null
     */
    private function directoryViewData($response): ?array
    {
        $data = $response->original->getData();

        return array_key_exists('directory', $data) ? $data['directory'] : null;
    }

    private function createNamedMember(
        Workspace $workspace,
        string $firstName,
        string $lastName,
        array $overrides = []
    ): \App\Models\WorkspaceMembership {
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($firstName) . '.' . strtolower($lastName) . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        return $this->createMembership($workspace, $user, $overrides);
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
