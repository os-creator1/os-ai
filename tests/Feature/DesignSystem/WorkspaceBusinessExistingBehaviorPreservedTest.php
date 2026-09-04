<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 A2 (Workspace / Business) — real-HTTP behavior-
 * preservation proof for the restyled 8-view surface. This is NOT a
 * duplicate of the exhaustive Workspace/Business backend suites (already
 * covered by tests/Feature/Workspace/**, tests/Feature/Business/**,
 * unmodified here) — it proves only that the presentation-layer restyle
 * left routes, form field names, role/scope UI boundaries, the
 * data-workspace-action/data-member-action/data-business-action JS seams,
 * the corrected post-remediation industry_other behavior, and the two
 * excluded billing/entitlement regions intact. Asserts the corrected,
 * POST-remediation behavior only -- never the pre-remediation drift.
 */
class WorkspaceBusinessExistingBehaviorPreservedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_all_customer_and_admin_a2_route_names_still_resolve(): void
    {
        foreach ([
            'customer.workspaces.index',
            'customer.business.edit',
            'customer.business.update',
            'admin.workspaces.index',
            'admin.businesses.index',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Expected route [{$name}] to still be registered.");
        }
    }

    // -----------------------------------------------------------------
    // customer/workspaces/show.blade.php — role/scope UI boundaries and
    // JS seams survive the restyle
    // -----------------------------------------------------------------

    public function test_staff_member_sees_no_mutation_forms_on_the_restyled_workspace_show_page(): void
    {
        $owner = $this->actingAsHttpCustomer();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->user_id, 'is_active' => true]);
        $staffUser = $this->createStaffUser();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $staffUser->id,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $this->actingAs($staffUser);
        $this->ensureRequiredAppConfigRowsExist();
        $staffCustomer = Customer::create(['user_id' => $staffUser->id]);
        $staffCustomer->permissions = Customer::customerPermissions();
        $staffCustomer->save();

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertDontSee('data-workspace-action="rename"', false);
        $response->assertDontSee('data-workspace-action="deactivate"', false);
        $response->assertDontSee('data-workspace-action="ownership/transfer"', false);
        $response->assertDontSee('data-workspace-action="businesses"', false);
    }

    public function test_owner_sees_mutation_forms_with_data_action_attributes_and_the_js_block_present(): void
    {
        $owner = $this->actingAsHttpCustomer();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->user_id, 'is_active' => true]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('data-workspace-action="rename"', false);
        $response->assertSee('data-workspace-action="deactivate"', false);
        $response->assertSee('data-workspace-action="ownership/transfer"', false);
        $response->assertSee('data-workspace-action="businesses"', false);
        $response->assertSee('data-workspace-action="members"', false);
        $response->assertSee("document.querySelectorAll('form[data-workspace-action]')", false);
        $response->assertSee("document.querySelectorAll('select[name=\"business_access_scope\"]')", false);
        $response->assertSee("document.querySelectorAll('select[name=\"previous_owner_disposition\"]')", false);
    }

    public function test_inactive_workspace_still_shows_read_only_overview_with_zero_business_list(): void
    {
        $owner = $this->actingAsHttpCustomer();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->user_id, 'is_active' => false]);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('Inactive');
        $response->assertSee('No Businesses are accessible in this Workspace.');
    }

    // -----------------------------------------------------------------
    // customer/business/edit.blade.php — corrected post-remediation
    // behavior through the restyled view
    // -----------------------------------------------------------------

    public function test_inactive_workspace_still_denies_the_restyled_customer_business_edit_page(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        Workspace::whereKey($business->workspace_id)->update(['is_active' => false]);

        $this->get(route('customer.business.edit'))->assertNotFound();
    }

    public function test_active_workspace_customer_business_update_still_persists_through_the_restyled_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes(['name' => 'Restyled Booth Co']))
            ->assertRedirect(route('customer.business.edit'));

        $this->assertSame('Restyled Booth Co', Business::find($business->id)->name);
    }

    public function test_other_industry_with_valid_detail_still_persists_through_the_restyled_customer_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes([
            'industry' => 'other',
            'industry_other' => 'Mobile Detailing',
        ]))->assertRedirect(route('customer.business.edit'));

        $fresh = Business::find($business->id);
        $this->assertSame('other', $fresh->industry->value);
        $this->assertSame('Mobile Detailing', $fresh->industry_other);
    }

    public function test_other_industry_without_detail_still_fails_validation_on_the_restyled_customer_form(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->put(route('customer.business.update'), $this->businessAttributes(['industry' => 'other']))
            ->assertSessionHasErrors('industry_other');
    }

    public function test_restyled_customer_edit_form_renders_the_industry_other_field_unconditionally(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $response = $this->get(route('customer.business.edit'))->assertOk();

        $response->assertSee('name="industry_other"', false);
    }

    // -----------------------------------------------------------------
    // Admin index pagination/filter preservation
    // -----------------------------------------------------------------

    public function test_admin_businesses_index_still_preserves_search_filter_across_pagination(): void
    {
        $admin = $this->actingAsHttpAdmin(['access backend', 'view business']);

        for ($i = 0; $i < 3; $i++) {
            $customer = $this->createCustomer();
            $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => "Matching Booth {$i}"]));
        }

        $response = $this->get(route('admin.businesses.index', ['search' => 'Matching']))->assertOk();

        $response->assertSee('value="Matching"', false);
    }

    // -----------------------------------------------------------------
    // Excluded billing/entitlement regions still render unchanged
    // -----------------------------------------------------------------

    public function test_admin_workspace_show_plan_entitlement_region_still_renders_natively_when_permitted(): void
    {
        $admin = $this->actingAsHttpAdmin(['access backend', 'view workspace', 'view workspace plans']);

        $owner = $this->createCustomer();
        $workspace = Workspace::create(['name' => 'Entitled Workspace', 'owner_user_id' => $owner->user_id, 'is_active' => true]);

        $response = $this->get(route('admin.workspaces.show', $workspace))->assertOk();

        $response->assertSee('Plan &amp; Entitlement', false);
        $response->assertSee('Explain effective entitlement');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createStaffUser(): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'email' => 'staff' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
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

    private function actingAsHttpAdmin(array $permissions = ['access backend', 'view business']): User
    {
        $this->ensureRequiredAppConfigRowsExist();

        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
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
}
