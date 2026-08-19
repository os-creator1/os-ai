<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme Editor corrective fix: Create, Save/Update, Duplicate, and Rename
 * must redirect back to the index carrying `?preset=<uid>` for the
 * just-created/edited preset, so the page-load JS (which already
 * prioritizes the `?preset=` query parameter) keeps that preset selected
 * instead of silently falling back to the active/default preset.
 */
class PlatformThemePresetRedirectSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_store_redirects_with_the_new_presets_uid(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $response = $this->post(route('admin.theme-presets.store'), ['name' => 'Selection Test']);

        $preset = PlatformThemePreset::query()->where('name', 'Selection Test')->firstOrFail();
        $response->assertRedirect(route('admin.theme-presets.index', ['preset' => $preset->uid]));
    }

    public function test_update_redirects_with_the_same_presets_uid(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $this->post(route('admin.theme-presets.store'), ['name' => 'Editable Draft']);
        $draft = PlatformThemePreset::query()->where('name', 'Editable Draft')->firstOrFail();

        $response = $this->put(route('admin.theme-presets.update', $draft->uid), $this->validTokenPayload());

        $response->assertRedirect(route('admin.theme-presets.index', ['preset' => $draft->uid]));
    }

    public function test_duplicate_redirects_with_the_duplicates_uid_not_the_sources(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();

        $response = $this->post(route('admin.theme-presets.duplicate', $factory->uid));

        $duplicate = PlatformThemePreset::query()->where('name', 'Warm Red copy')->firstOrFail();
        $response->assertRedirect(route('admin.theme-presets.index', ['preset' => $duplicate->uid]));
    }

    public function test_rename_redirects_with_the_same_presets_uid(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $this->post(route('admin.theme-presets.store'), ['name' => 'Rename Me']);
        $draft = PlatformThemePreset::query()->where('name', 'Rename Me')->firstOrFail();

        $response = $this->post(route('admin.theme-presets.rename', $draft->uid), ['name' => 'Renamed']);

        $response->assertRedirect(route('admin.theme-presets.index', ['preset' => $draft->uid]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validTokenPayload(): array
    {
        return [
            'primary' => '#123456',
            'secondary' => '#654321',
            'canvas' => '#FFFFFF',
            'sidebar' => '#F2F0EB',
            'surface' => '#FFFFFF',
            'surface_secondary' => '#FBFAF7',
            'input' => '#FFFFFF',
            'border' => '#E5E1DA',
            'status_success' => '#28C76F',
            'status_warning' => '#FF9F43',
            'status_danger' => '#EA5455',
            'status_info' => '#00CFE8',
            'status_pending' => '#B58B46',
            'chart_1' => '#B5524C', 'chart_2' => '#647C78', 'chart_3' => '#4E79A7', 'chart_4' => '#59A14F',
            'chart_5' => '#F28E2B', 'chart_6' => '#B07AA1', 'chart_7' => '#EDC948', 'chart_8' => '#76B7B2',
            'chart_neutral' => '#A8A29A',
            'font_choice' => 'bundled',
            'font_bundled_key' => 'geist',
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
