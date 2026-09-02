<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    private static bool $createdAiSettingsTable = false;
    private static bool $ephemeralSchemaEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureEphemeralSchema();

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

    public function test_customer_dashboard_has_zero_data_feather_and_genuine_ds_icon_adoption(): void
    {
        $source = file_get_contents(resource_path('views/customer/dashboard.blade.php'));

        $this->assertStringNotContainsString('data-feather', $source);
        $this->assertStringContainsString('<x-ds-icon', $source);
        $this->assertSame(15, substr_count($source, '<x-ds-icon'));
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

    public function test_platform_theme_remains_used_in_both_dashboard_chart_files(): void
    {
        $customerSource = file_get_contents(resource_path('views/customer/dashboard.blade.php'));
        $adminSource = file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        $this->assertStringContainsString("PlatformTheme.color('color-chart-negative')", $customerSource);
        $this->assertStringContainsString("PlatformTheme.color('color-chart-negative')", $adminSource);
        $this->assertStringContainsString("PlatformTheme.color('color-chart-6')", $adminSource);
        $this->assertStringContainsString("PlatformTheme.color('color-status-danger-border')", $adminSource);
    }

    public function test_ai_settings_renders_through_shared_layout_chrome(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedAiSettingsRow();
        $admin = $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/ai-brain');

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

    private function seedAiSettingsRow(string $systemPrompt = 'Original prompt', string $model = 'gpt-3.5'): void
    {
        DB::table('ai_settings')->updateOrInsert(['id' => 1], [
            'system_prompt' => $systemPrompt,
            'model' => $model,
        ]);
    }

    /**
     * §14.1-equivalent ephemeral schema fixture (reproduced here per the
     * merged Slice-3 contract's own allowance). Only ai_settings is needed
     * by this file — it never renders Hot Leads/AI Analytics, so the
     * chat_boxes/ai_box_campaign_map fixtures used elsewhere are not
     * required here.
     */
    private function ephemeralSchema(): \Illuminate\Database\Schema\Builder
    {
        config(['database.connections.security_test_ddl' => config('database.connections.mysql')]);

        return Schema::connection('security_test_ddl');
    }

    private function ensureEphemeralSchema(): void
    {
        if (self::$ephemeralSchemaEnsured) {
            return;
        }

        self::$ephemeralSchemaEnsured = true;

        $schema = $this->ephemeralSchema();

        if ($schema->hasTable('ai_settings')) {
            return;
        }

        $schema->create('ai_settings', function ($table) {
            $table->id();
            $table->text('system_prompt')->nullable();
            $table->string('model')->nullable();
        });

        self::$createdAiSettingsTable = true;

        $dsn = 'mysql:host=' . config('database.connections.mysql.host')
            . ';port=' . config('database.connections.mysql.port')
            . ';dbname=' . config('database.connections.mysql.database')
            . ';charset=' . config('database.connections.mysql.charset', 'utf8mb4');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        register_shutdown_function(function () use ($dsn, $username, $password) {
            try {
                $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('DROP TABLE IF EXISTS `ai_settings`');
            } catch (\Throwable $e) {
                // Best-effort cleanup at process shutdown; nothing further can be reported here.
            }
        });
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
