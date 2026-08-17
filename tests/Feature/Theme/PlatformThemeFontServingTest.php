<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemeFont;
use App\Models\PlatformThemePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.9/§6.18/§9 item 84. The public
 * route serves only the currently active preset's font, never trusting
 * the requested filename alone; the 'manage theme'-protected preview
 * route may serve any preset's font to the in-editor preview.
 */
class PlatformThemeFontServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        Storage::fake('local');
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_public_route_serves_the_active_presets_font(): void
    {
        $font = $this->createStoredFont('active-font.woff2');
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $factory->update(['font_choice' => PlatformThemePreset::FONT_CHOICE_UPLOADED, 'font_uploaded_id' => $font->id]);

        $response = $this->get(route('theme-font.active', $font->safe_filename));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'font/woff2');
    }

    public function test_public_route_404s_for_a_font_not_referenced_by_the_active_preset(): void
    {
        $font = $this->createStoredFont('unreferenced.woff2');
        // Factory (active) uses its bundled font, not $font.

        $this->get(route('theme-font.active', $font->safe_filename))->assertNotFound();
    }

    public function test_public_route_404s_when_the_requested_filename_does_not_match_the_actual_active_font(): void
    {
        $activeFont = $this->createStoredFont('real-active.woff2');
        $otherFont = $this->createStoredFont('spoofed.woff2');
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $factory->update(['font_choice' => PlatformThemePreset::FONT_CHOICE_UPLOADED, 'font_uploaded_id' => $activeFont->id]);

        $this->get(route('theme-font.active', $otherFont->safe_filename))->assertNotFound();
    }

    public function test_public_route_is_reachable_without_authentication(): void
    {
        $font = $this->createStoredFont('public-active.woff2');
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $factory->update(['font_choice' => PlatformThemePreset::FONT_CHOICE_UPLOADED, 'font_uploaded_id' => $font->id]);

        $this->get(route('theme-font.active', $font->safe_filename))->assertOk();
    }

    public function test_preview_route_requires_manage_theme_and_can_serve_any_preset_font(): void
    {
        $font = $this->createStoredFont('inactive-preview.woff2');
        // Not referenced by the active preset at all.

        $this->get(route('admin.theme-fonts.preview', $font->id))->assertUnauthorized();

        $this->actingAsAdmin(['access backend', 'manage theme']);
        $this->get(route('admin.theme-fonts.preview', $font->id))->assertOk();
    }

    private function createStoredFont(string $safeFilename): PlatformThemeFont
    {
        Storage::disk('local')->put("theme-fonts/{$safeFilename}", 'wOF2' . str_repeat('X', 200));

        return PlatformThemeFont::query()->create([
            'safe_filename' => $safeFilename,
            'original_filename' => $safeFilename,
            'mime_type' => 'font/woff2',
            'file_size_bytes' => 204,
            'storage_path' => "theme-fonts/{$safeFilename}",
        ]);
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
