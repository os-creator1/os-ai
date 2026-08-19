<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Theme Editor corrective fix: the edit form must submit as POST (with
 * Laravel's PUT method spoofing) instead of silently falling back to a
 * browser-default GET, and the Rename/Duplicate/Delete/Activate controls
 * must actually render — the shared <x-card> component only renders its
 * actions slot when given a `title`, which this page never sets.
 */
class PlatformThemeSettingsIndexRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_edit_form_submits_as_post_with_put_method_spoofing(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $html = $this->get(route('admin.theme-presets.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form id="theme-preset-form"[^>]*method="POST"[^>]*>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="_method"[^>]*value="PUT"[^>]*>/',
            $html
        );
    }

    public function test_preset_action_controls_are_rendered(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $html = $this->get(route('admin.theme-presets.index'))->assertOk()->getContent();

        foreach (['theme-preset-rename-btn', 'theme-preset-duplicate-btn', 'theme-preset-delete-btn', 'theme-preset-activate-btn'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "Missing control: {$id}");
        }
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
