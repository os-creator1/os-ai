<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-003 Milestone 5 (docs/automation/RFC-003-M5-CONTRACT.md): the
 * admin-only, intentionally cross-tenant, READ-ONLY Workspace inspection
 * surface. Covers route shape, the three-layer admin boundary (blanket
 * 'can:access backend', EnsureUserIsAdministrator, 'view workspace' Gate),
 * cross-tenant inspection independent of the acting admin's own customer-
 * side Workspace relationships, index filtering/search, and every show-page
 * data point required by the contract.
 */
class AdminWorkspaceControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    // --- Route shape ---------------------------------------------------

    public function test_index_and_show_routes_exist_as_get_only(): void
    {
        $this->assertTrue(Route::has('admin.workspaces.index'));
        $this->assertTrue(Route::has('admin.workspaces.show'));

        $indexRoute = Route::getRoutes()->getByName('admin.workspaces.index');
        $showRoute = Route::getRoutes()->getByName('admin.workspaces.show');

        $this->assertContains('GET', $indexRoute->methods());
        $this->assertNotContains('POST', $indexRoute->methods());
        $this->assertNotContains('PUT', $indexRoute->methods());
        $this->assertNotContains('DELETE', $indexRoute->methods());

        $this->assertContains('GET', $showRoute->methods());
        $this->assertNotContains('POST', $showRoute->methods());
        $this->assertNotContains('PUT', $showRoute->methods());
        $this->assertNotContains('DELETE', $showRoute->methods());
    }

    public function test_show_route_uses_workspace_uid_binding(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->assertSame('uid', $workspace->getRouteKeyName());
    }

    // --- Guests / non-admins ---------------------------------------------

    public function test_guest_cannot_access_index(): void
    {
        $this->get(route('admin.workspaces.index'))->assertUnauthorized();
    }

    public function test_guest_cannot_access_show(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->get(route('admin.workspaces.show', $workspace))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_either_route(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAs($customer->user);

        $this->get(route('admin.workspaces.index'))->assertUnauthorized();
        $this->get(route('admin.workspaces.show', $workspace))->assertUnauthorized();
    }

    /**
     * Direct regression for the EnsureUserIsAdministrator defense-in-depth
     * pattern already proven for Business admin routes: a non-admin account
     * must remain blocked even when its session carries the exact
     * permission strings the feature-level checks look for.
     */
    public function test_a_non_admin_account_is_blocked_even_with_workspace_permissions_in_session(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->withSession(['permissions' => collect(['access backend', 'view workspace'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.workspaces.index'))->assertUnauthorized();
        $this->get(route('admin.workspaces.show', $workspace))->assertUnauthorized();
    }

    // --- Admin permission boundary -----------------------------------------

    public function test_admin_without_access_backend_permission_is_blocked(): void
    {
        $this->actingAsAdmin([]);

        $this->get(route('admin.workspaces.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_workspace_permission_cannot_view_index(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.workspaces.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_workspace_permission_cannot_view_show(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.workspaces.show', $workspace))->assertUnauthorized();
    }

    public function test_view_only_admin_can_view_index(): void
    {
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Visible Workspace']);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index'))->assertOk()->assertSee('Visible Workspace');
    }

    // --- Cross-tenant inspection ---------------------------------------

    public function test_authorized_admin_can_inspect_a_workspace_they_do_not_own(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user, ['name' => "Someone Else's Workspace"]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee("Someone Else's Workspace");
    }

    public function test_authorized_admin_can_inspect_an_inactive_workspace(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user, ['name' => 'Inactive Workspace', 'is_active' => false]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee('Inactive Workspace');
    }

    /**
     * The acting admin's own customer-side Workspace membership/scope, in a
     * completely unrelated Workspace, must have zero bearing on what they
     * can inspect elsewhere — proving no customer-side authorization
     * primitive leaks into platform-admin inspection.
     */
    public function test_admins_own_unrelated_workspace_membership_scope_does_not_restrict_inspection(): void
    {
        $owner = $this->createCustomer()->user;
        $inspectedWorkspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $inspectedWorkspace->id);
        $admin = $this->actingAsAdmin(['access backend', 'view workspace']);

        $adminsOwnWorkspace = $this->createWorkspace($this->createCustomer()->user);
        $this->createMembership($adminsOwnWorkspace, $admin, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->get(route('admin.workspaces.show', $inspectedWorkspace))->assertOk()->assertSee($business->name);
    }

    // --- Index data points -----------------------------------------------

    public function test_index_shows_owner(): void
    {
        $owner = $this->createCustomer()->user;
        $owner->first_name = 'Ollie';
        $owner->last_name = 'Owns';
        $owner->save();
        $this->createWorkspace($owner);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index'))->assertOk()->assertSee($owner->displayName());
    }

    public function test_index_shows_active_and_inactive_state(): void
    {
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Active One', 'is_active' => true]);
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Inactive One', 'is_active' => false]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $response = $this->get(route('admin.workspaces.index'))->assertOk();
        $response->assertSee('Active');
        $response->assertSee('Inactive');
    }

    public function test_index_shows_business_count(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $response = $this->get(route('admin.workspaces.index'))->assertOk();
        $row = collect($response->original->getData()['workspaces']->items())->firstWhere('id', $workspace->id);

        $this->assertSame(2, $row->businesses_count);
    }

    public function test_index_shows_active_membership_count(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => true]);
        $this->createMembership($workspace, $this->createCustomer()->user, ['is_active' => false]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $response = $this->get(route('admin.workspaces.index'))->assertOk();
        $row = collect($response->original->getData()['workspaces']->items())->firstWhere('id', $workspace->id);

        $this->assertSame(1, $row->active_memberships_count);
    }

    // --- Index search/filter -----------------------------------------------

    public function test_search_by_workspace_name_works(): void
    {
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Acme Ops']);
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Unrelated Co']);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['search' => 'Acme']))
            ->assertOk()
            ->assertSee('Acme Ops')
            ->assertDontSee('Unrelated Co');
    }

    public function test_search_by_workspace_uid_works(): void
    {
        $matching = $this->createWorkspace($this->createCustomer()->user, ['name' => 'First Workspace']);
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Second Workspace']);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['search' => $matching->uid]))
            ->assertOk()
            ->assertSee('First Workspace')
            ->assertDontSee('Second Workspace');
    }

    public function test_is_active_true_filter_works(): void
    {
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Active WS', 'is_active' => true]);
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Inactive WS', 'is_active' => false]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['is_active' => '1']))
            ->assertOk()
            ->assertSee('Active WS')
            ->assertDontSee('Inactive WS');
    }

    public function test_is_active_false_filter_works(): void
    {
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Active WS', 'is_active' => true]);
        $this->createWorkspace($this->createCustomer()->user, ['name' => 'Inactive WS', 'is_active' => false]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['is_active' => '0']))
            ->assertOk()
            ->assertSee('Inactive WS')
            ->assertDontSee('Active WS');
    }

    public function test_per_page_validation_rejects_values_above_100(): void
    {
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['per_page' => 101]))
            ->assertSessionHasErrors('per_page');
    }

    public function test_invalid_is_active_input_fails_validation(): void
    {
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.index', ['is_active' => 'not-a-boolean']))
            ->assertSessionHasErrors('is_active');
    }

    // --- Show data points --------------------------------------------------

    public function test_show_displays_owner_separately_from_memberships(): void
    {
        $owner = $this->createCustomer()->user;
        $owner->first_name = 'Ozzy';
        $owner->last_name = 'Owner';
        $owner->save();
        $workspace = $this->createWorkspace($owner, ['name' => 'Owned Workspace']);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee($owner->displayName());

        $this->assertSame(
            0,
            WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $owner->id)->count()
        );
    }

    public function test_show_displays_businesses_in_the_workspace(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee($business->name);
    }

    public function test_show_displays_active_and_inactive_memberships_with_role(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);

        $activeAdmin = $this->createCustomer()->user;
        $activeAdmin->first_name = 'Active';
        $activeAdmin->last_name = 'Admin';
        $activeAdmin->save();
        $this->createMembership($workspace, $activeAdmin, ['role' => WorkspaceMembershipRole::Admin, 'is_active' => true]);

        $inactiveStaff = $this->createCustomer()->user;
        $inactiveStaff->first_name = 'Inactive';
        $inactiveStaff->last_name = 'Staffer';
        $inactiveStaff->save();
        $this->createMembership($workspace, $inactiveStaff, ['role' => WorkspaceMembershipRole::Staff, 'is_active' => false]);

        $this->actingAsAdmin(['access backend', 'view workspace']);

        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $response->assertSee($activeAdmin->displayName());
        $response->assertSee('Admin');
        $response->assertSee($inactiveStaff->displayName());
        $response->assertSee('Staff');
    }

    public function test_selected_scope_displays_exact_assigned_businesses(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $assigned = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $unassigned = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $membership = $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $membership->id, 'business_id' => $assigned->id]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        // Both Businesses legitimately appear somewhere on the page (the
        // separate Businesses section lists every Business in the
        // Workspace regardless of assignment), so the exact per-membership
        // assignment is asserted against the view data directly rather
        // than raw page text. Compared by id, not name: the shared
        // businessAttributes() fixture default gives both Businesses the
        // same name unless overridden, so a name-based comparison cannot
        // distinguish them.
        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $renderedWorkspace = $response->original->getData()['workspace'];
        $renderedMembership = $renderedWorkspace->memberships->firstWhere('id', $membership->id);
        $assignedIds = $renderedMembership->assignedBusinesses->pluck('id')->all();

        $this->assertSame([$assigned->id], $assignedIds);
        $this->assertNotContains($unassigned->id, $assignedIds);
    }

    public function test_selected_scope_with_zero_assignments_displays_empty_state(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
        ]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee('No Businesses assigned');
    }

    public function test_all_scope_displays_all_businesses_label(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $this->createMembership($workspace, $this->createCustomer()->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertSee('All Businesses');
    }

    // --- uid/id boundary and mutation absence -------------------------------

    public function test_numeric_workspace_id_in_place_of_uid_does_not_expose_the_record(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $this->get(url(config('app.admin_path') . '/workspaces/' . $workspace->id))->assertNotFound();
    }

    /**
     * RFC-004 Milestone 3 (§19 of the M3 contract) legitimately adds 8
     * Workspace-scoped entitlement mutation routes under the
     * `admin.workspaces.*` name prefix -- this is no longer a zero-mutation
     * surface. The exact canonical list below is the full, closed set;
     * `admin.workspace-plan-catalog.index` is deliberately NOT
     * `admin.workspaces.*`-named (it is a separate, global, read-only
     * catalog route) and is asserted separately.
     */
    public function test_workspace_mutation_routes_are_exactly_the_m3_entitlement_surface(): void
    {
        $unexpectedMutationNames = [
            'admin.workspaces.store',
            'admin.workspaces.create',
            'admin.workspaces.update',
            'admin.workspaces.destroy',
            'admin.workspaces.deactivate',
            'admin.workspaces.reactivate',
            'admin.workspaces.transfer',
        ];

        foreach ($unexpectedMutationNames as $name) {
            $this->assertFalse(Route::has($name), "Unexpected mutation route registered: {$name}");
        }

        $canonicalWorkspaceRouteNames = [
            'admin.workspaces.index',
            'admin.workspaces.show',
            'admin.workspaces.plan.assign',
            'admin.workspaces.plan.change',
            'admin.workspaces.plan.status',
            'admin.workspaces.plan.complimentary.grant',
            'admin.workspaces.plan.complimentary.revoke',
            'admin.workspaces.plan.additional-slots',
            'admin.workspaces.entitlement-overrides.store',
            'admin.workspaces.entitlement-overrides.revert',
        ];

        foreach ($canonicalWorkspaceRouteNames as $name) {
            $this->assertTrue(Route::has($name), "Expected canonical route missing: {$name}");
        }

        $registeredWorkspaceAdminRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.workspaces.'))
            ->map(fn ($route) => $route->getName())
            ->unique();

        $this->assertSame(10, $registeredWorkspaceAdminRoutes->count());
        $this->assertEqualsCanonicalizing($canonicalWorkspaceRouteNames, $registeredWorkspaceAdminRoutes->values()->all());

        $this->assertTrue(Route::has('admin.workspace-plan-catalog.index'));
        $this->assertFalse($registeredWorkspaceAdminRoutes->contains('admin.workspace-plan-catalog.index'));
    }

    /**
     * Scoped to the M5 Workspace section only (#admin-workspaces-index /
     * #admin-workspace-show) rather than the full rendered page: the shared
     * application layout legitimately contains its own global POST logout
     * form, so a whole-page assertDontSee('method="POST"') is a false
     * positive against layout markup this slice does not own and must not
     * touch.
     */
    public function test_rendered_views_contain_no_mutation_controls(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'view workspace']);

        $indexSection = $this->extractSection(
            $this->get(route('admin.workspaces.index'))->assertOk()->getContent(),
            'admin-workspaces-index'
        );
        $this->assertNoMutationControls($indexSection);

        $showSection = $this->extractSection(
            $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent(),
            'admin-workspace-show'
        );
        $this->assertNoMutationControls($showSection);
    }

    /**
     * RFC-004 Milestone 3: the two new entitlement cards are deliberately
     * separate, distinct sections from #admin-workspace-show (§19) --
     * proving the M5 read-only section's own no-mutation-controls
     * assertion above remains valid unchanged, while the new cards
     * correctly gate their controls by the new `manage workspace plans`
     * permission rather than the existing read-only `view workspace`.
     */
    public function test_plan_entitlement_mutation_controls_are_gated_by_manage_permission(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->actingAsAdmin(['access backend', 'view workspace', 'view workspace plans']);
        $viewOnlyResponse = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $viewOnlySection = $this->extractSection($viewOnlyResponse->getContent(), 'admin-workspace-plan-entitlement');
        $this->assertNoMutationControls($viewOnlySection);
        $this->assertStringNotContainsString('id="admin-workspace-plan-mutate"', $viewOnlyResponse->getContent());

        $this->actingAsAdmin(['access backend', 'view workspace', 'view workspace plans', 'manage workspace plans']);
        $manageResponse = $this->get(route('admin.workspaces.show', $workspace))->assertOk();
        $mutateSection = $this->extractSection($manageResponse->getContent(), 'admin-workspace-plan-mutate');
        $this->assertStringContainsString('method="POST"', $mutateSection);
    }

    /**
     * RFC-004 Milestone 3 correction round 1, item 2: `view workspace plans`
     * and `manage workspace plans` are independent permissions -- an admin
     * can legitimately hold `manage workspace plans` without `view
     * workspace plans`. Both cards render from the same entitlementSummary,
     * so it must be loaded whenever either permission is present; the read
     * card itself must still stay invisible without `view workspace plans`.
     */
    public function test_manage_permission_without_view_permission_does_not_crash_and_shows_only_the_mutate_card(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'view workspace', 'manage workspace plans']);

        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();

        $response->assertSee('id="admin-workspace-plan-mutate"', false);
        $response->assertDontSee('id="admin-workspace-plan-entitlement"', false);
    }

    /**
     * RFC-004 Milestone 3 correction round 1, item 5: the effective-
     * entitlement explanation query's unknown-feature-key and
     * Business-outside-this-Workspace boundaries both fail closed with
     * 404, matching Blocker 4's addressability rule.
     */
    public function test_explanation_with_an_unknown_feature_key_is_not_found(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $business = $this->createBusinessForCustomer($owner->id, $workspace->id);
        $this->actingAsAdmin(['access backend', 'view workspace', 'view workspace plans']);

        $this->get(route('admin.workspaces.show', $workspace) . '?business_uid=' . $business->uid . '&feature_key=not-a-real-feature')
            ->assertNotFound();
    }

    public function test_explanation_with_a_business_uid_outside_this_workspace_is_not_found(): void
    {
        $owner = $this->createCustomer()->user;
        $workspace = $this->createWorkspace($owner);
        $foreignOwner = $this->createCustomer()->user;
        $foreignWorkspace = $this->createWorkspace($foreignOwner);
        $foreignBusiness = $this->createBusinessForCustomer($foreignOwner->id, $foreignWorkspace->id);
        $this->actingAsAdmin(['access backend', 'view workspace', 'view workspace plans']);

        $this->get(route('admin.workspaces.show', $workspace) . '?business_uid=' . $foreignBusiness->uid . '&feature_key=crm')
            ->assertNotFound();
    }

    private function extractSection(string $html, string $sectionId): string
    {
        $idPosition = strpos($html, 'id="' . $sectionId . '"');
        $this->assertNotFalse($idPosition, "Could not locate the #{$sectionId} section in the rendered page.");

        // A plain substring slice from the opening <section> tag to its
        // first </section> is sufficient because this Workspace section
        // never itself contains a nested <section> element.
        $sectionStart = strrpos(substr($html, 0, $idPosition), '<section');
        $sectionEnd = strpos($html, '</section>', $idPosition);

        return substr($html, $sectionStart, $sectionEnd - $sectionStart);
    }

    private function assertNoMutationControls(string $sectionHtml): void
    {
        $this->assertStringNotContainsString('method="POST"', $sectionHtml);
        $this->assertStringNotContainsString('method="PUT"', $sectionHtml);
        $this->assertStringNotContainsString('method="PATCH"', $sectionHtml);
        $this->assertStringNotContainsString('method="DELETE"', $sectionHtml);
        $this->assertStringNotContainsString('name="_method"', $sectionHtml);
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

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }
}
