<?php

namespace Tests\Feature\Theme;

use App\Library\Theme\Exceptions\UnsafeThemeContrastException;
use App\Library\Theme\PlatformThemeManager;
use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.6/§9 item 76. Advanced-mode
 * overrides win over the corresponding derived value, survive an
 * unrelated re-derivation, and are validated by the exact same contrast
 * rules as every derived value.
 */
class ThemeAdvancedOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_override_wins_over_the_derived_value(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraft();

        $this->put(route('admin.theme-presets.update', $draft->uid), array_merge(
            $this->validTokenPayload(),
            ['overrides' => ['color-focus-border' => '#FF00FF']]
        ));

        $draft->refresh();
        $this->assertSame('#FF00FF', $draft->derived_tokens_json['color-focus-border']);
        $this->assertSame('#FF00FF', $draft->overrides_json['color-focus-border']);
    }

    public function test_override_survives_an_unrelated_later_edit(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraft();

        $this->put(route('admin.theme-presets.update', $draft->uid), array_merge(
            $this->validTokenPayload(),
            ['overrides' => ['color-focus-border' => '#FF00FF']]
        ));

        // A later edit that changes an unrelated base field (secondary)
        // must not silently discard the earlier override.
        $this->put(route('admin.theme-presets.update', $draft->uid), array_merge(
            $this->validTokenPayload(),
            ['secondary' => '#111111', 'overrides' => ['color-focus-border' => '#FF00FF']]
        ));

        $draft->refresh();
        $this->assertSame('#FF00FF', $draft->derived_tokens_json['color-focus-border']);
        $this->assertSame('#111111', $draft->derived_tokens_json['color-secondary']);
    }

    public function test_an_unwhitelisted_override_key_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraft();

        $response = $this->put(route('admin.theme-presets.update', $draft->uid), array_merge(
            $this->validTokenPayload(),
            ['overrides' => ['not-a-real-token' => '#FF00FF']]
        ));

        $response->assertSessionHasErrors('overrides.not-a-real-token');
    }

    public function test_an_override_that_fails_contrast_is_rejected_at_save(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraft();

        // Near-identical foreground/background: guaranteed to fail 4.5:1.
        $this->expectException(UnsafeThemeContrastException::class);
        app(PlatformThemeManager::class)->edit(
            $draft,
            $this->validTokenPayload(),
            ['color-status-success-text' => '#28C76F'],
            'bundled',
            'geist',
            null,
            null
        );
    }

    private function createDraft(): PlatformThemePreset
    {
        $this->post(route('admin.theme-presets.store'), ['name' => 'Override Draft']);

        return PlatformThemePreset::query()->where('name', 'Override Draft')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validTokenPayload(): array
    {
        return [
            'primary' => '#B5524C', 'secondary' => '#8A6F67', 'canvas' => '#F7F6F2', 'sidebar' => '#F2F0EB',
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
