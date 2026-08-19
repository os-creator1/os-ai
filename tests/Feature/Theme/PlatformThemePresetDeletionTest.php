<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemeFont;
use App\Models\PlatformThemePreset;
use App\Models\PlatformThemePresetEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §9 item 74. Deleting Factory or the
 * active preset is rejected; deleting an inactive, non-factory preset
 * succeeds and records the event; deleting a font still referenced by
 * any preset is rejected (§6.16).
 */
class PlatformThemePresetDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_factory_preset_cannot_be_deleted(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();

        $this->delete(route('admin.theme-presets.destroy', $factory->uid))->assertSessionHasErrors('preset');
        $this->assertDatabaseHas('platform_theme_presets', ['id' => $factory->id]);
    }

    public function test_active_preset_cannot_be_deleted(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $this->assertSame(PlatformThemePreset::STATUS_ACTIVE, $factory->status);

        $this->delete(route('admin.theme-presets.destroy', $factory->uid))->assertSessionHasErrors('preset');
    }

    public function test_inactive_non_factory_preset_can_be_deleted(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();

        $this->delete(route('admin.theme-presets.destroy', $clearRed->uid))->assertRedirect();

        $this->assertDatabaseMissing('platform_theme_presets', ['id' => $clearRed->id]);
        $this->assertDatabaseHas('platform_theme_preset_events', [
            'preset_id' => $clearRed->id,
            'event_type' => PlatformThemePresetEvent::EVENT_DELETED,
        ]);
    }

    public function test_font_referenced_by_a_preset_cannot_be_deleted(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $font = PlatformThemeFont::query()->create([
            'safe_filename' => 'abc123.woff2',
            'original_filename' => 'MyFont.woff2',
            'mime_type' => 'font/woff2',
            'file_size_bytes' => 12345,
            'storage_path' => 'theme-fonts/abc123.woff2',
        ]);

        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();
        $clearRed->update(['font_choice' => PlatformThemePreset::FONT_CHOICE_UPLOADED, 'font_uploaded_id' => $font->id]);

        $this->delete(route('admin.theme-fonts.destroy', $font->id))->assertSessionHasErrors('font');
        $this->assertDatabaseHas('platform_theme_fonts', ['id' => $font->id, 'deleted_at' => null]);
    }

    public function test_font_not_referenced_by_any_preset_can_be_deleted(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $font = PlatformThemeFont::query()->create([
            'safe_filename' => 'unused123.woff2',
            'original_filename' => 'Unused.woff2',
            'mime_type' => 'font/woff2',
            'file_size_bytes' => 12345,
            'storage_path' => 'theme-fonts/unused123.woff2',
        ]);

        $this->delete(route('admin.theme-fonts.destroy', $font->id))->assertRedirect();

        $this->assertSoftDeleted('platform_theme_fonts', ['id' => $font->id]);
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
