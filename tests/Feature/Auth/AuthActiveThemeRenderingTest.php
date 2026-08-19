<?php

namespace Tests\Feature\Auth;

use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 2 contract §5 item 11/§8 item 8. Proves the
 * active-theme customization already reaching authenticated admin pages
 * (Slice 1's own test surface) also reaches a genuinely unauthenticated,
 * pre-login page for the first time -- the login page's own
 * `<style id="platform-theme-overrides">` block must reflect whichever
 * preset is currently active, and the page must still render correctly
 * with the Factory preset (or no active preset) standing in.
 */
class AuthActiveThemeRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_login_page_renders_the_currently_active_non_factory_preset(): void
    {
        $admin = $this->actingAsAdmin(['access backend', 'manage theme']);
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();

        $this->post(route('admin.theme-presets.activate', $clearRed->uid))->assertRedirect();

        auth()->logout();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('id="platform-theme-overrides"', $html);
    }

    public function test_login_page_still_renders_correctly_with_the_factory_preset_active(): void
    {
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $this->assertSame(PlatformThemePreset::STATUS_ACTIVE, $factory->status);

        $this->get(route('login'))->assertOk()->assertSee(__('locale.auth.login'));
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
