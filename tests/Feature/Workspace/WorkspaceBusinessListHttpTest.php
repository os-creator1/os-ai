<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 3 Slice 3C: the access-filtered Business list embedded
 * on GET customer.workspaces.show
 * (docs/automation/RFC-003-M3-SLICE-3C-CONTRACT.md, "Locked Slice 3C
 * contract"). Covers the RFC-003 §14.1 effective-access filter over
 * WorkspaceRepository::businessesForWorkspace(), the display-only row
 * shape, and the absence of any new route or link.
 */
class WorkspaceBusinessListHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    public function test_no_separate_business_list_or_mutation_route_is_introduced(): void
    {
        $this->assertFalse(Route::has('customer.workspaces.businesses.index'));

        $route = Route::getRoutes()->getByName('customer.workspaces.show');
        $this->assertNotNull($route);
        $this->assertSame('workspaces/{workspaceUid}', $route->uri());
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            $this->assertNotContains($verb, $route->methods());
        }
    }

    public function test_guest_is_rejected(): void
    {
        $this->get(route('customer.workspaces.show', ['workspaceUid' => 'anything']))->assertUnauthorized();
    }

    public function test_unrelated_user_receives_not_found_and_no_business_leaks(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createNamedBusiness($owner->id, $workspace->id, 'Hidden Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]));

        $response->assertNotFound();
        $response->assertDontSee('Hidden Co');
    }

    public function test_owner_sees_every_business_in_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $businessA = $this->createNamedBusiness($customer->user_id, $workspace->id, 'Alpha Studio');
        $otherCustomer = $this->createCustomer();
        $businessB = $this->createNamedBusiness($otherCustomer->user_id, $workspace->id, 'Beta Studio');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['Alpha Studio', 'Beta Studio'],
            array_column($this->businessesViewData($response), 'name')
        );
        $this->assertLessThan($businessB->id, $businessA->id);
    }

    public function test_active_all_scope_admin_sees_every_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);
        $this->createNamedBusiness($owner->id, $workspace->id, 'Alpha Studio');
        $this->createNamedBusiness($owner->id, $workspace->id, 'Beta Studio');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(
            ['Alpha Studio', 'Beta Studio'],
            array_column($this->businessesViewData($response), 'name')
        );
    }

    public function test_active_all_scope_staff_sees_every_business(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);
        $this->createNamedBusiness($owner->id, $workspace->id, 'Alpha Studio');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(['Alpha Studio'], array_column($this->businessesViewData($response), 'name'));
    }

    public function test_selected_scope_admin_sees_only_assigned_businesses(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $granted = $this->createNamedBusiness($owner->id, $workspace->id, 'Granted Co');
        $this->createNamedBusiness($owner->id, $workspace->id, 'Not Granted Co');

        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $granted);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(['Granted Co'], array_column($this->businessesViewData($response), 'name'));
    }

    public function test_selected_scope_staff_sees_only_assigned_businesses(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $granted = $this->createNamedBusiness($owner->id, $workspace->id, 'Granted Co');
        $this->createNamedBusiness($owner->id, $workspace->id, 'Not Granted Co');

        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $granted);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(['Granted Co'], array_column($this->businessesViewData($response), 'name'));
    }

    public function test_selected_scope_with_no_assignments_sees_empty_business_list(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $this->createNamedBusiness($owner->id, $workspace->id, 'Not Granted Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame([], $this->businessesViewData($response));
        $response->assertSee('No Businesses are accessible in this Workspace.');
    }

    public function test_role_never_widens_business_scope(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $this->createNamedBusiness($owner->id, $workspace->id, 'Not Granted Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame([], $this->businessesViewData($response));
    }

    public function test_direct_business_ownership_is_an_independent_access_path_within_the_workspace(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        // customer.user holds a selected-scope membership with no grants,
        // but also directly owns a Business inside this same Workspace —
        // RFC-003 §14.1 keeps direct ownership as an independent access
        // path once the Workspace boundary itself is satisfied.
        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        $this->createNamedBusiness($customer->user_id, $workspace->id, 'My Direct Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame(['My Direct Co'], array_column($this->businessesViewData($response), 'name'));
    }

    public function test_inactive_workspace_remains_viewable_but_leaks_no_business_name(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user, ['is_active' => false]);
        $this->createNamedBusiness($customer->user_id, $workspace->id, 'Dormant Business Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame([], $this->businessesViewData($response));
        $response->assertDontSee('Dormant Business Co');
    }

    public function test_no_foreign_workspace_business_appears(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $otherWorkspace = $this->createWorkspace($this->createCustomer()->user);
        $this->createNamedBusiness($customer->user_id, $otherWorkspace->id, 'Foreign Co');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertSame([], $this->businessesViewData($response));
        $response->assertDontSee('Foreign Co');
    }

    public function test_business_row_exposes_exactly_the_name_key(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $business = $this->createNamedBusiness($customer->user_id, $workspace->id, 'Alpha Studio');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $rows = $this->businessesViewData($response);
        $this->assertCount(1, $rows);
        $this->assertSame(['name'], array_keys($rows[0]));
        $this->assertSame('Alpha Studio', $rows[0]['name']);

        $response->assertDontSee((string) $business->id);
        $response->assertDontSee($business->uid);
        $response->assertDontSee('/business', false);
        $response->assertDontSee('customer.business.edit', false);
    }

    public function test_business_names_are_escaped(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedBusiness($customer->user_id, $workspace->id, '<script>alert(1)</script>');

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_businesses_render_for_owner_admin_and_staff_alike(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $this->createNamedBusiness($owner->user_id, $workspace->id, 'Shared Co');

        $admin = $this->actingAsHttpCustomer();
        $this->createMembership($workspace, $admin->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $response = $this->get(route('customer.workspaces.show', ['workspaceUid' => $workspace->uid]))->assertOk();

        $this->assertArrayHasKey('businesses', $response->original->getData());
        $this->assertSame(['Shared Co'], array_column($this->businessesViewData($response), 'name'));
    }

    public function test_get_produces_no_database_writes(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = $this->createWorkspace($customer->user);
        $this->createNamedBusiness($customer->user_id, $workspace->id, 'Snapshot Co');

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
     * @return array<int, array{name: string}>
     */
    private function businessesViewData($response): array
    {
        return $response->original->getData()['businesses'];
    }

    /**
     * createBusinessForCustomer() (CreatesBusinessTestData) always uses the
     * fixture's default name, so distinct-name/ordering assertions in this
     * file need their own helper to set a chosen name via
     * businessAttributes()'s override array.
     */
    private function createNamedBusiness(int $customerId, int $workspaceId, string $name): Business
    {
        $business = new Business($this->businessAttributes(['name' => $name]));
        $business->customer_id = $customerId;
        $business->workspace_id = $workspaceId;
        $business->save();

        return $business;
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
