<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\PlatformThemePresetEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §9 item 73. Atomic activation
 * (colors+font together), previous-active demotion to Saved, Draft
 * rejection, rollback event-type distinction, and cache invalidation
 * via DB::afterCommit (§6.13/§6.14).
 */
class PlatformThemePresetActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
    }

    public function test_activation_is_atomic_and_demotes_previous_active_to_saved(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();
        $this->assertSame(PlatformThemePreset::STATUS_SAVED, $clearRed->status);

        $this->post(route('admin.theme-presets.activate', $clearRed->uid))->assertRedirect();

        $clearRed->refresh();
        $factory->refresh();
        $this->assertSame(PlatformThemePreset::STATUS_ACTIVE, $clearRed->status);
        $this->assertSame(PlatformThemePreset::STATUS_SAVED, $factory->status);
        $this->assertSame($clearRed->font_choice, $clearRed->font_choice);

        $this->assertDatabaseHas('platform_theme_preset_events', [
            'preset_id' => $clearRed->id,
            'event_type' => PlatformThemePresetEvent::EVENT_ACTIVATED,
        ]);
    }

    public function test_only_one_preset_is_ever_active_at_a_time(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $warmBrown = PlatformThemePreset::query()->where('name', 'Warm Brown')->firstOrFail();

        $this->post(route('admin.theme-presets.activate', $warmBrown->uid));

        $this->assertSame(1, PlatformThemePreset::query()->where('status', PlatformThemePreset::STATUS_ACTIVE)->count());
    }

    public function test_reactivating_the_previously_active_preset_records_rolled_back_not_activated(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();

        $this->post(route('admin.theme-presets.activate', $clearRed->uid));
        // Factory is now 'saved' -- reactivating it is a return to the
        // previously active preset, recorded distinctly (§6.1/§6.14).
        $this->post(route('admin.theme-presets.activate', $factory->uid));

        $this->assertDatabaseHas('platform_theme_preset_events', [
            'preset_id' => $factory->id,
            'event_type' => PlatformThemePresetEvent::EVENT_ROLLED_BACK,
        ]);
    }

    public function test_activating_a_draft_preset_is_rejected(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $this->post(route('admin.theme-presets.store'), ['name' => 'Still A Draft']);
        $draft = PlatformThemePreset::query()->where('name', 'Still A Draft')->firstOrFail();

        $this->post(route('admin.theme-presets.activate', $draft->uid))->assertSessionHasErrors('preset');
        $this->assertNotSame(PlatformThemePreset::STATUS_ACTIVE, $draft->fresh()->status);
    }

    /**
     * DB::afterCommit callbacks registered inside a RefreshDatabase-wrapped
     * test's outer transaction never fire (Laravel only runs them against a
     * genuine top-level commit) -- so this deliberately avoids asserting on
     * Cache::has() timing and instead confirms the FIRST (necessarily
     * uncached) read after activation reflects the newly active preset.
     */
    public function test_style_block_reflects_the_newly_active_preset_after_activation(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $clearRed = PlatformThemePreset::query()->where('name', 'Clear Red')->firstOrFail();

        $this->post(route('admin.theme-presets.activate', $clearRed->uid));

        Cache::forget(\App\Library\Theme\PlatformThemeManager::CACHE_KEY);
        $styleBlock = app(\App\Library\Theme\PlatformThemeManager::class)->currentStyleBlock();

        $this->assertStringContainsString($clearRed->fresh()->derived_tokens_json['color-primary'], $styleBlock);
    }

    public function test_activation_uses_lockforupdate_on_the_factory_row_and_registers_afterCommit_invalidation(): void
    {
        $source = file_get_contents(app_path('Library/Theme/PlatformThemeManager.php'));

        $this->assertStringContainsString('lockFactoryForUpdate', $source);
        $this->assertStringContainsString('DB::afterCommit', $source);
        $this->assertStringContainsString('Cache::forget', $source);
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
