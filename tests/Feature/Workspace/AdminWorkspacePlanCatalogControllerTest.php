<?php

namespace Tests\Feature\Workspace;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * RFC-004 Milestone 3 (docs/automation/RFC-004-M3-CONTRACT.md §11): the
 * admin-only, read-only Workspace plan catalog inspection surface. Covers
 * route shape, the admin authority matrix, and the exact 3-tier catalog
 * data rendered from EntitlementManager::listPlanCatalogSummaries() -- never
 * a repository read in the controller.
 */
class AdminWorkspacePlanCatalogControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_index_route_exists_as_get_only(): void
    {
        $this->assertTrue(Route::has('admin.workspace-plan-catalog.index'));

        $route = Route::getRoutes()->getByName('admin.workspace-plan-catalog.index');

        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('POST', $route->methods());
        $this->assertNotContains('PUT', $route->methods());
        $this->assertNotContains('DELETE', $route->methods());
    }

    public function test_guest_cannot_access_index(): void
    {
        $this->get(route('admin.workspace-plan-catalog.index'))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_index(): void
    {
        $customer = $this->createCustomer();
        $this->actingAs($customer->user);

        $this->get(route('admin.workspace-plan-catalog.index'))->assertUnauthorized();
    }

    public function test_admin_without_permission_cannot_access_index(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.workspace-plan-catalog.index'))->assertUnauthorized();
    }

    public function test_authorized_admin_can_view_index_with_exact_three_tier_data(): void
    {
        $this->actingAsAdmin(['access backend', 'view workspace plans']);

        $response = $this->get(route('admin.workspace-plan-catalog.index'))->assertOk();
        $summaries = $response->original->getData()['catalogSummaries'];

        $this->assertCount(3, $summaries);
        $response->assertSee('Core');
        $response->assertSee('Growth');
        $response->assertSee('Agency');
    }

    public function test_index_renders_no_mutation_controls(): void
    {
        $this->actingAsAdmin(['access backend', 'view workspace plans']);

        $response = $this->get(route('admin.workspace-plan-catalog.index'))->assertOk();

        $response->assertDontSee('method="POST"', false);
        $response->assertDontSee('method="PUT"', false);
        $response->assertDontSee('method="DELETE"', false);
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
