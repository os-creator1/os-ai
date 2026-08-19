<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.15/§9 item 79. Only 'manage
 * theme' may create/edit/activate/rename/duplicate/delete presets or
 * manage fonts (§4.6) -- verified across guests, ordinary customers, and
 * admins missing the specific permission.
 */
class PlatformThemePresetAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            ['GET', 'admin.theme-presets.index'],
            ['POST', 'admin.theme-presets.store'],
        ];
    }

    /**
     * @dataProvider protectedRoutes
     */
    public function test_guest_cannot_access_protected_routes(string $method, string $routeName): void
    {
        $response = $this->call($method, route($routeName), $method === 'POST' ? ['name' => 'X'] : []);
        $response->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_manage_theme(): void
    {
        $customer = $this->createCustomer();
        $this->actingAs($customer->user);

        $this->get(route('admin.theme-presets.index'))->assertUnauthorized();
    }

    public function test_admin_without_manage_theme_permission_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.theme-presets.index'))->assertUnauthorized();
        $this->post(route('admin.theme-presets.store'), ['name' => 'X'])->assertUnauthorized();

        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $this->post(route('admin.theme-presets.activate', $factory->uid))->assertUnauthorized();
        $this->delete(route('admin.theme-presets.destroy', $factory->uid))->assertUnauthorized();
    }

    public function test_admin_with_manage_theme_permission_is_authorized(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $this->get(route('admin.theme-presets.index'))->assertOk();
    }

    public function test_font_upload_and_preview_routes_require_manage_theme(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->post(route('admin.theme-fonts.upload'), [])->assertUnauthorized();
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
