<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 3 contract §8 item 2 — mechanical content proof
 * that the design-system migration actually took effect, not merely
 * claimed. Raw-source assertions for implementation details (icon/color
 * literals), HTTP/render assertions for actual layout behavior
 * (ai-settings' new layout chrome). No full-page snapshots.
 *
 * Corrected, Implementation Correction Round 1 — the original version of
 * this test wrongly accepted two defects as acceptable: (1)
 * resources/js/core/theme-tokens.js's own cssVar(name, fallback) prepends
 * "--" to `name` itself, so a caller passing a leading `--` (as this
 * implementation originally did) reads a malformed, never-matching custom
 * property name and always falls through to its hardcoded fallback — not
 * genuinely runtime-token-reactive despite appearances; (2) the original
 * "no purple hue anywhere in the palette" STOP-finding claim was false —
 * resources/scss/base/tokens/_colors.scss's own `--color-chart-6`
 * (#B07AA1) is a real, existing, chart-specific purple/mauve token, and
 * `--color-status-danger-border` (#F7C1C2) is a real, existing light-red
 * danger-family token — both suitable existing replacements for the
 * legacy #9c8cfc/#f29292 gradient endpoints, requiring no new token. This
 * test now proves all four legacy literals are fully gone from both
 * dashboard files, that every PlatformTheme.color() call uses the correct
 * no-leading-dash name form, and that the exact required token calls are
 * present.
 */
class DashboardDesignSystemContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    /**
     * Customer Experience Slice 4 rebuilt the customer dashboard as a page
     * plus band partials (resources/views/customer/dashboard/**). Its icons
     * reach the centralized ds-icon seam through the M2 primitives' own
     * `icon` props (x-button, x-alert), so the count is taken across the
     * whole rebuilt view set: three icon adoptions (quick actions, the
     * degraded-band notice, the team member's Login as Parent), and not one
     * data-feather.
     */
    public function test_customer_dashboard_has_zero_data_feather_and_genuine_ds_icon_adoption(): void
    {
        $sources = [file_get_contents(resource_path('views/customer/dashboard.blade.php'))];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/customer/dashboard'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $sources[] = file_get_contents($file->getPathname());
        }
        $source = implode("\n", $sources);

        $this->assertStringNotContainsString('data-feather', $source);
        // `->` inside a bound attribute is not the end of the tag.
        $adoptions = substr_count($source, '<x-ds-icon') + preg_match_all('/<x-(button|alert)\b(?:->|[^>])*?\s:?icon=/', $source);
        $this->assertSame(3, $adoptions);
    }

    public function test_admin_dashboard_has_zero_data_feather_and_genuine_ds_icon_adoption(): void
    {
        $source = file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        $this->assertStringNotContainsString('data-feather', $source);
        $this->assertStringContainsString('<x-ds-icon', $source);
        $this->assertSame(19, substr_count($source, '<x-ds-icon'));
    }

    /**
     * Corrected, Implementation Correction Round 1 — all four legacy
     * literals (#EA5455, #9c8cfc, #f29292, #7367F0) must be completely
     * absent from both dashboard files. No fallback-argument exception:
     * theme-tokens.js's own `--color-chart-negative` custom property is
     * guaranteed by the merged token architecture, so no hex fallback is
     * needed or authorized inside these views.
     */
    public function test_customer_dashboard_has_zero_legacy_color_literals(): void
    {
        $source = file_get_contents(resource_path('views/customer/dashboard.blade.php'));

        foreach (['#EA5455', '#9c8cfc', '#f29292', '#7367F0'] as $literal) {
            $this->assertStringNotContainsString($literal, $source, "Expected {$literal} to be fully absent from customer/dashboard.blade.php.");
        }
    }

    public function test_admin_dashboard_has_zero_legacy_color_literals(): void
    {
        $source = file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        foreach (['#EA5455', '#9c8cfc', '#f29292', '#7367F0'] as $literal) {
            $this->assertStringNotContainsString($literal, $source, "Expected {$literal} to be fully absent from admin/dashboard.blade.php.");
        }
    }

    /**
     * theme-tokens.js's own cssVar(name, fallback) prepends "--" to `name`
     * itself — a caller passing a leading `--` reads a malformed,
     * never-matching custom property name. Neither dashboard file may
     * call PlatformTheme.color() with a leading `--`.
     */
    public function test_neither_dashboard_file_uses_the_incorrect_leading_dash_call_form(): void
    {
        $customerSource = file_get_contents(resource_path('views/customer/dashboard.blade.php'));
        $adminSource = file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        $this->assertStringNotContainsString("PlatformTheme.color('--", $customerSource);
        $this->assertStringNotContainsString("PlatformTheme.color('--", $adminSource);
    }

    /**
     * B5 Business Analytics (contract §18.4/§18.5) removed every chart from
     * the customer dashboard — its message figures now live in the
     * Business-scoped Analytics overview, which ChartTokenContentTest
     * covers — so the customer dashboard is no longer a chart file and
     * only the admin dashboard is asserted here.
     */
    public function test_platform_theme_remains_used_in_the_admin_dashboard_chart_file(): void
    {
        $customerSource = file_get_contents(resource_path('views/customer/dashboard.blade.php'));
        $adminSource = file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        $this->assertStringNotContainsString('ApexCharts', $customerSource, 'The customer dashboard bears no chart since B5.');
        $this->assertStringContainsString("PlatformTheme.color('color-chart-negative')", $adminSource);
        $this->assertStringContainsString("PlatformTheme.color('color-chart-6')", $adminSource);
        $this->assertStringContainsString("PlatformTheme.color('color-status-danger-border')", $adminSource);
    }

    /**
     * B3 Simplified Platform Settings (2026-09) retargeted this from the
     * orphan `/admin/ai-brain` surface (deleted outright -- an
     * ai_settings table with no migration anywhere in this repository,
     * confirmed by exhaustive search, and a system_prompt field with zero
     * production consumers) to the one canonical platform AI provider
     * configuration surface, admin/settings' own AI section
     * (config('services.openai.*'), the same seam the existing campaign
     * AI feature and Lane A's Agency Prospecting runtime both read). The
     * assertions are unchanged in substance: shared layout chrome
     * (core.css) actually renders, and the AI section's own model field
     * is present.
     */
    public function test_ai_settings_renders_through_shared_layout_chrome(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
        $response->assertSee('core.css', false);
        $response->assertSee('<label for="model"', false);
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

    private function ensureRequiredAppConfigRowsExist(): void
    {
        // B3 Simplified Platform Settings: this file's own retargeted
        // test now renders admin.settings.platform.index (the AI section
        // lives there, not a standalone page), which additionally reads
        // the company_address/php_bin_path app_config rows -- seeded here
        // the same way every other settings-adjacent test in this
        // repository seeds exactly the rows its own render path touches.
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script', 'company_address', 'php_bin_path'])
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

        if (! in_array('company_address', $existing, true)) {
            AppConfig::create(['setting' => 'company_address', 'value' => 'Test Address']);
        }

        if (! in_array('php_bin_path', $existing, true)) {
            AppConfig::create(['setting' => 'php_bin_path', 'value' => '/usr/bin/php']);
        }
    }
}
