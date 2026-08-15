<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspaceEntitlementOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-004 Milestone 3 (docs/automation/RFC-004-M3-CONTRACT.md §11): the
 * admin Workspace plan/capacity/entitlement mutation surface. Every action
 * delegates entirely to EntitlementManager -- this controller never reads
 * or writes an entitlement repository directly.
 */
class AdminWorkspaceEntitlementControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    private function fixtureAdminId(): int
    {
        return User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin',
            'email' => 'fixtureadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function assignComplimentaryCorePlan(Workspace $workspace, int $additionalBusinessSlots = 0): void
    {
        app(EntitlementManager::class)->assignFirstPlan(
            $workspace, WorkspacePlanTier::Core, $this->fixtureAdminId(), 'Fixture assignment.', true, $additionalBusinessSlots,
        );
    }

    // --- Route shape ---------------------------------------------------

    public function test_all_eight_mutation_routes_exist_with_expected_verbs(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $expected = [
            ['admin.workspaces.plan.assign', 'POST'],
            ['admin.workspaces.plan.change', 'POST'],
            ['admin.workspaces.plan.status', 'POST'],
            ['admin.workspaces.plan.complimentary.grant', 'POST'],
            ['admin.workspaces.plan.complimentary.revoke', 'DELETE'],
            ['admin.workspaces.plan.additional-slots', 'POST'],
            ['admin.workspaces.entitlement-overrides.store', 'POST'],
        ];

        foreach ($expected as [$name, $verb]) {
            $this->assertTrue(Route::has($name), "Missing route [{$name}]");
            $route = Route::getRoutes()->getByName($name);
            $this->assertContains($verb, $route->methods());
        }

        $this->assertTrue(Route::has('admin.workspaces.entitlement-overrides.revert'));
        $revertRoute = Route::getRoutes()->getByName('admin.workspaces.entitlement-overrides.revert');
        $this->assertContains('DELETE', $revertRoute->methods());
    }

    // --- Authority matrix (representative action: assignPlan) --------------

    public function test_guest_cannot_assign_plan(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core', 'reason' => 'x'])
            ->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_assign_plan(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAs($customer->user);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core', 'reason' => 'x'])
            ->assertUnauthorized();
    }

    public function test_admin_with_view_only_permission_cannot_assign_plan(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'view workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core', 'reason' => 'x'])
            ->assertUnauthorized();
    }

    public function test_admin_with_manage_permission_can_assign_plan(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core', 'reason' => 'x', 'is_complimentary' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace));

        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id]);
    }

    // --- Happy path per action ----------------------------------------------

    public function test_admin_can_change_plan(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.change', $workspace), ['tier' => 'growth'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $assignment = WorkspacePlanAssignment::where('workspace_id', $workspace->id)->first();
        $this->assertSame('growth', $assignment->catalog->tier->value);
    }

    public function test_admin_can_change_plan_status(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.status', $workspace), ['status' => 'suspended', 'reason' => 'Fraud review.'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'status' => 'suspended']);
    }

    public function test_admin_can_grant_and_revoke_complimentary_status(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $currency = \App\Models\Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$1', 'status' => true]);
        \App\Models\WorkspacePlanCatalog::where('tier', 'core')->update(['price' => '10.00', 'currency_id' => $currency->id]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->fixtureAdminId(), 'Fixture.', false, 0);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.complimentary.grant', $workspace), ['reason' => 'Goodwill.'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'is_complimentary' => true]);

        $this->delete(route('admin.workspaces.plan.complimentary.revoke', $workspace), ['reason' => 'Trial ended.'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'is_complimentary' => false]);
    }

    public function test_admin_can_update_additional_slots(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.additional-slots', $workspace), ['additional_business_slots' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'additional_business_slots' => 1]);
    }

    public function test_admin_can_store_and_revert_an_override(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.entitlement-overrides.store', $workspace), [
            'feature_key' => 'crm', 'state' => 'deny', 'reason' => 'Abuse.',
        ])->assertRedirect(route('admin.workspaces.show', $workspace))->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_entitlement_overrides', ['workspace_id' => $workspace->id, 'feature_key' => 'crm', 'state' => 'deny']);

        $this->delete(route('admin.workspaces.entitlement-overrides.revert', [$workspace, 'crm']))
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $this->assertSame(0, WorkspaceEntitlementOverride::where('workspace_id', $workspace->id)->count());
    }

    // --- Validation ----------------------------------------------------

    public function test_assign_plan_requires_a_reason(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, WorkspacePlanAssignment::where('workspace_id', $workspace->id)->count());
    }

    public function test_change_status_requires_a_reason(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.status', $workspace), ['status' => 'suspended'])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'status' => 'active']);
    }

    public function test_grant_complimentary_requires_a_reason(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->fixtureAdminId(), 'Fixture.', false, 0);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.complimentary.grant', $workspace), [])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'is_complimentary' => false]);
    }

    public function test_store_override_requires_a_reason(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.entitlement-overrides.store', $workspace), ['feature_key' => 'crm', 'state' => 'deny'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(0, WorkspaceEntitlementOverride::where('workspace_id', $workspace->id)->count());
    }

    // --- Typed exception -> flash_error, no partial mutation ---------------

    public function test_assigning_a_plan_twice_maps_to_flash_error(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'growth', 'reason' => 'x', 'is_complimentary' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_error');

        $this->assertSame(1, WorkspacePlanAssignment::where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'additional_business_slots' => 0]);
    }

    public function test_changing_plan_on_an_unassigned_workspace_maps_to_flash_error(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.change', $workspace), ['tier' => 'growth'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_error');

        $this->assertSame(0, WorkspacePlanAssignment::where('workspace_id', $workspace->id)->count());
    }

    public function test_updating_additional_slots_on_an_unassigned_workspace_maps_to_flash_error(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.additional-slots', $workspace), ['additional_business_slots' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_error');
    }

    public function test_storing_an_allow_override_for_an_unavailable_feature_maps_to_flash_error(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.entitlement-overrides.store', $workspace), [
            'feature_key' => 'calendar', 'state' => 'allow', 'reason' => 'Trying to unlock a Planned feature.',
        ])->assertRedirect(route('admin.workspaces.show', $workspace))->assertSessionHas('flash_error');

        $this->assertSame(0, WorkspaceEntitlementOverride::where('workspace_id', $workspace->id)->count());
    }

    // --- 404 boundaries --------------------------------------------------

    public function test_unknown_workspace_uid_is_not_found(): void
    {
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(url(config('app.admin_path') . '/workspaces/does-not-exist/plan'), ['tier' => 'core', 'reason' => 'x'])
            ->assertNotFound();
    }

    public function test_revert_override_with_an_unknown_feature_key_is_not_found_before_any_entitlement_manager_call(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace, PlatformFeature::Crm, \App\Enums\Entitlement\WorkspaceEntitlementOverrideState::Deny, $this->fixtureAdminId(), 'Fixture override.'
        );
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->delete(route('admin.workspaces.entitlement-overrides.revert', [$workspace, 'not-a-real-feature']))
            ->assertNotFound();

        // The pre-existing crm override must be untouched -- proof the
        // unknown-key 404 fires before EntitlementManager is ever called.
        $this->assertSame(1, WorkspaceEntitlementOverride::where('workspace_id', $workspace->id)->count());
    }

    // --- Residual scalar-conversion proofs ----------------------------------

    public function test_assign_plan_with_additional_business_slots_omitted_defaults_to_zero(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), ['tier' => 'core', 'reason' => 'x', 'is_complimentary' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'additional_business_slots' => 0]);
    }

    public function test_assign_plan_with_additional_business_slots_as_html_form_string_persists_the_real_integer(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.assign', $workspace), [
            'tier' => 'core', 'reason' => 'x', 'is_complimentary' => '1', 'additional_business_slots' => '1',
        ])->assertRedirect(route('admin.workspaces.show', $workspace))->assertSessionHas('flash_success');

        $assignment = WorkspacePlanAssignment::where('workspace_id', $workspace->id)->first();
        $this->assertSame(1, $assignment->additional_business_slots);
        $this->assertIsInt($assignment->additional_business_slots);
    }

    public function test_change_plan_with_additional_business_slots_omitted_preserves_null_and_is_not_coerced_to_zero(): void
    {
        // Core->Growth is a Core<->Growth transition, so
        // normalizeSlotsForDirection() requires the incoming value to be
        // genuinely null (never 0) -- proof the controller preserves null
        // rather than coercing an absent field to the integer 0.
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace, 0);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.change', $workspace), ['tier' => 'growth'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspace->id, 'additional_business_slots' => 0]);
    }

    public function test_update_additional_slots_with_html_form_string_persists_the_real_integer(): void
    {
        $workspace = $this->createWorkspace($this->createCustomer()->user);
        $this->assignComplimentaryCorePlan($workspace);
        $this->actingAsAdmin(['access backend', 'manage workspace plans']);

        $this->post(route('admin.workspaces.plan.additional-slots', $workspace), ['additional_business_slots' => '1'])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('flash_success');

        $assignment = WorkspacePlanAssignment::where('workspace_id', $workspace->id)->first();
        $this->assertSame(1, $assignment->additional_business_slots);
        $this->assertIsInt($assignment->additional_business_slots);
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
