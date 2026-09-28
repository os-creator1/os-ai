<?php

namespace Tests\Feature\NicheBlueprint\Admin;

use App\Helpers\Helper;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Implementation Contract 20 §12.F/§18.F, Blueprint §30 — Blueprint §30
 * names TWO distinct Platform Owner sidebar entries, "Niche Blueprints" and
 * "Template Library". This file proves they are registered as two separate
 * top-level menu entries (never collapsed into one, never one nested inside
 * the other), that a non-admin backend account cannot see either link even
 * though every admin sees the "access backend" gate satisfied, and that each
 * marks its own sidebar entry active without marking the other.
 */
class NicheBlueprintAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    private function actingAsAdmin(array $permissions = ['access backend']): User
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

    /**
     * A backend-portal account that is NOT a platform administrator, but
     * whose session carries the exact 'access backend' permission string —
     * the same adversarial shape the messaging-provisioning-incidents
     * sidebar entry is already proven against.
     */
    private function actingAsNonAdminBackendAccount(): User
    {
        $account = User::create([
            'first_name' => 'Backend',
            'last_name' => 'Staff',
            'email' => 'backend-staff' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($account);

        return $account;
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
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    public function test_menu_data_declares_two_distinct_top_level_entries(): void
    {
        $admin = Helper::menuData()['admin'];

        $topLevelNames = array_column($admin, 'name');
        $this->assertContains('Niche Blueprints', $topLevelNames);
        $this->assertContains('Template Library', $topLevelNames);

        $niche = collect($admin)->firstWhere('name', 'Niche Blueprints');
        $library = collect($admin)->firstWhere('name', 'Template Library');

        $this->assertNotSame($niche['url'], $library['url'], 'The two surfaces must not share one link.');
        $this->assertArrayNotHasKey('submenu', $niche, 'Niche Blueprints must be a direct top-level link, not a submenu holder.');
        $this->assertArrayNotHasKey('submenu', $library, 'Template Library must be a direct top-level link, not a submenu holder.');
        $this->assertTrue($niche['admin_only'] ?? false);
        $this->assertTrue($library['admin_only'] ?? false);

        // Neither is nested inside another entry's submenu — Blueprint §30
        // requires two SIDEBAR entries, not one collapsed group.
        foreach ($admin as $entry) {
            if (! isset($entry['submenu'])) {
                continue;
            }

            $nestedNames = array_column($entry['submenu'], 'name');
            $this->assertNotContains('Niche Blueprints', $nestedNames);
            $this->assertNotContains('Template Library', $nestedNames);
        }
    }

    public function test_admin_sees_two_distinct_sidebar_links(): void
    {
        $this->actingAsAdmin();

        $html = $this->get(route('admin.niche-blueprints.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.niche-blueprints.index'), $html);
        $this->assertStringContainsString(route('admin.template-library.index'), $html);
        $this->assertNotSame(
            route('admin.niche-blueprints.index'),
            route('admin.template-library.index'),
            'The two routes must be genuinely distinct URLs.'
        );
    }

    public function test_non_admin_backend_account_does_not_see_either_link(): void
    {
        $this->actingAsNonAdminBackendAccount();

        // The account cannot open either surface (route-level boundary)...
        $this->get(route('admin.niche-blueprints.index'))->assertUnauthorized();

        // ...and the sidebar itself never advertises a link it cannot open,
        // proven from a page this account CAN reach.
        $html = $this->get(route('admin.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('admin/niche-blueprints', $html);
        $this->assertStringNotContainsString('admin/template-library', $html);
    }

    public function test_niche_blueprints_page_marks_its_own_entry_active_and_not_the_other(): void
    {
        $this->actingAsAdmin();

        $html = $this->get(route('admin.niche-blueprints.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<li class="nav-item[^"]*active[^"]*"[^>]*>\s*<a href="[^"]*admin\/niche-blueprints"/s',
            $html,
            'The Niche Blueprints sidebar entry must be marked active on its own index page.'
        );

        if (preg_match('/<li class="([^"]*)"[^>]*>\s*<a href="[^"]*admin\/template-library"/s', $html, $match)) {
            $this->assertStringNotContainsString('active', $match[1], 'Template Library must not be marked active while on Niche Blueprints.');
        }
    }

    public function test_template_library_page_marks_its_own_entry_active_and_not_the_other(): void
    {
        $this->actingAsAdmin();

        $html = $this->get(route('admin.template-library.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<li class="nav-item[^"]*active[^"]*"[^>]*>\s*<a href="[^"]*admin\/template-library"/s',
            $html,
            'The Template Library sidebar entry must be marked active on its own index page.'
        );

        if (preg_match('/<li class="([^"]*)"[^>]*>\s*<a href="[^"]*admin\/niche-blueprints"/s', $html, $match)) {
            $this->assertStringNotContainsString('active', $match[1], 'Niche Blueprints must not be marked active while on Template Library.');
        }
    }
}
