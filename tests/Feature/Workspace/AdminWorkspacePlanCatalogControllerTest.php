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

    /**
     * Scoped to the #admin-workspace-plan-catalog-index section rather than
     * the full rendered page: the shared application layout legitimately
     * contains its own global POST logout form, so a whole-page
     * assertDontSee('method="POST"') is a false positive against layout
     * markup this slice does not own and must not touch -- mirrors
     * AdminWorkspaceControllerTest::test_rendered_views_contain_no_mutation_controls()'s
     * existing precedent exactly.
     */
    public function test_index_renders_no_mutation_controls(): void
    {
        $this->actingAsAdmin(['access backend', 'view workspace plans']);

        $response = $this->get(route('admin.workspace-plan-catalog.index'))->assertOk();
        $section = $this->extractSection($response->getContent(), 'admin-workspace-plan-catalog-index');

        $this->assertStringNotContainsString('method="POST"', $section);
        $this->assertStringNotContainsString('method="PUT"', $section);
        $this->assertStringNotContainsString('method="DELETE"', $section);
        $this->assertStringNotContainsString('name="_method"', $section);
    }

    private function extractSection(string $html, string $sectionId): string
    {
        $idPosition = strpos($html, 'id="' . $sectionId . '"');
        $this->assertNotFalse($idPosition, "Could not locate the #{$sectionId} section in the rendered page.");

        // A plain substring slice from the opening <section> tag to its
        // first </section> is sufficient because this section never itself
        // contains a nested <section> element.
        $sectionStart = strrpos(substr($html, 0, $idPosition), '<section');
        $sectionEnd = strpos($html, '</section>', $idPosition);

        return substr($html, $sectionStart, $sectionEnd - $sectionStart);
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
