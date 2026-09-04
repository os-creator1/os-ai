<?php

namespace Tests\Feature\Theme;

use App\Library\Theme\PlatformThemeManager;
use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §7/§8 item 6. Proves the
 * fail-safe fix directly: `PlatformThemeManager::currentStyleBlock()`
 * returns null (never throws) when `platform_theme_presets` does not
 * exist yet, and that a real, fully-rendered page whose shared layout
 * calls it (`resources/views/panels/styles.blade.php`, included by
 * `layouts/fullLayoutMaster`) still renders 200 in that state.
 *
 * Re-homed off the legacy `Installer::welcome` route (removed by the
 * Safe Legacy / Dead-Surface Deletion Sweep, 2026-09) onto `GET /login`
 * -- both are guest-accessible, pre-authentication routes rendered
 * through a layout that includes the same styles partial, so the
 * regression this test guards against is exercised identically.
 *
 * Deliberately does not drop or rename any real table: MySQL DDL like
 * `DROP TABLE` implicitly commits and would break RefreshDatabase's
 * transaction-rollback isolation for every later test in the same
 * process. `Schema::hasTable('platform_theme_presets')` is partially
 * mocked instead, scoped to exactly one expected call so an unexpected
 * second Schema call fails loudly rather than silently passing.
 */
class PlatformThemePresetsMissingTableFailSafeTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_style_block_fails_safe_when_the_presets_table_does_not_exist(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('platform_theme_presets')
            ->andReturn(false);

        $result = app(PlatformThemeManager::class)->currentStyleBlock();

        $this->assertNull($result);
    }

    public function test_login_page_renders_ok_when_the_presets_table_does_not_exist(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        Schema::shouldReceive('hasTable')
            ->once()
            ->with('platform_theme_presets')
            ->andReturn(false);

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('auth-login-form', false);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')->all();

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
}
