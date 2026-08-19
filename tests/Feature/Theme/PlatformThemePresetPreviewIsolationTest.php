<?php

namespace Tests\Feature\Theme;

use App\Library\Theme\PlatformThemeManager;
use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §9 item 75. Saving or previewing an
 * inactive (Draft/Saved) preset never mutates the active theme or the
 * shared style-block cache (§6.13/§4.6).
 */
class PlatformThemePresetPreviewIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_editing_and_saving_an_inactive_preset_does_not_change_the_active_style_block(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $manager = app(PlatformThemeManager::class);

        Cache::forget(PlatformThemeManager::CACHE_KEY);
        $before = $manager->currentStyleBlock();

        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();
        $this->put(route('admin.theme-presets.update', $clearRed->uid), array_merge(
            $this->validTokenPayload(),
            ['primary' => '#00FF00']
        ));

        // Force a fresh, uncached read -- editing a non-active preset must
        // never have registered a cache-invalidation callback at all.
        Cache::forget(PlatformThemeManager::CACHE_KEY);
        $after = $manager->currentStyleBlock();

        $this->assertSame($before, $after);
        $this->assertStringContainsString($factory->fresh()->derived_tokens_json['color-primary'], $after);
        $this->assertStringNotContainsString('#00FF00', $after);
    }

    public function test_the_validate_endpoint_never_persists_any_change(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();
        $originalTokens = $clearRed->derived_tokens_json;

        $this->post(route('admin.theme-presets.validate'), array_merge(
            $this->validTokenPayload(),
            ['primary' => '#00FF00']
        ))->assertOk();

        $this->assertSame($originalTokens, $clearRed->fresh()->derived_tokens_json);
    }

    /**
     * @return array<string, mixed>
     */
    private function validTokenPayload(): array
    {
        return [
            'primary' => '#123456', 'secondary' => '#654321', 'canvas' => '#FFFFFF', 'sidebar' => '#F2F0EB',
            'surface' => '#FFFFFF', 'surface_secondary' => '#FBFAF7', 'input' => '#FFFFFF', 'border' => '#E5E1DA',
            'status_success' => '#28C76F', 'status_warning' => '#FF9F43', 'status_danger' => '#EA5455',
            'status_info' => '#00CFE8', 'status_pending' => '#B58B46',
            'chart_1' => '#B5524C', 'chart_2' => '#647C78', 'chart_3' => '#4E79A7', 'chart_4' => '#59A14F',
            'chart_5' => '#F28E2B', 'chart_6' => '#B07AA1', 'chart_7' => '#EDC948', 'chart_8' => '#76B7B2',
            'chart_neutral' => '#A8A29A', 'font_choice' => 'bundled', 'font_bundled_key' => 'geist',
        ];
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
