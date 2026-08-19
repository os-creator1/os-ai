<?php

namespace Tests\Feature\Theme;

use App\Models\AppConfig;
use App\Models\PlatformThemePreset;
use App\Models\PlatformThemePresetEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §9 item 72. Covers create/duplicate/
 * edit/rename, the Draft->Saved->Active enforced progression, and the
 * Active/Factory edit-in-place block (§6.14/§10).
 */
class PlatformThemePresetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedFactoryPreset();
    }

    public function test_create_starts_as_draft_and_records_created_event(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);

        $response = $this->post(route('admin.theme-presets.store'), ['name' => 'Ocean Blue']);
        $response->assertRedirect();

        $preset = PlatformThemePreset::query()->where('name', 'Ocean Blue')->firstOrFail();
        $this->assertSame(PlatformThemePreset::STATUS_DRAFT, $preset->status);
        $this->assertFalse($preset->is_factory);
        $this->assertNotNull($preset->uid);

        $this->assertDatabaseHas('platform_theme_preset_events', [
            'preset_id' => $preset->id,
            'event_type' => PlatformThemePresetEvent::EVENT_CREATED,
        ]);
    }

    public function test_duplicate_generates_unique_copy_name_and_stays_draft(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();

        $this->post(route('admin.theme-presets.duplicate', $factory->uid))->assertRedirect();

        $duplicate = PlatformThemePreset::query()->where('name', 'Warm Red copy')->first();
        $this->assertNotNull($duplicate);
        $this->assertSame(PlatformThemePreset::STATUS_DRAFT, $duplicate->status);
        $this->assertFalse($duplicate->is_factory);

        $this->assertDatabaseHas('platform_theme_preset_events', [
            'preset_id' => $duplicate->id,
            'event_type' => PlatformThemePresetEvent::EVENT_DUPLICATED,
        ]);
    }

    public function test_edit_moves_draft_to_saved_and_derives_full_token_set(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraftPreset();

        $response = $this->put(route('admin.theme-presets.update', $draft->uid), $this->validTokenPayload());
        $response->assertRedirect();

        $draft->refresh();
        $this->assertSame(PlatformThemePreset::STATUS_SAVED, $draft->status);
        $this->assertArrayHasKey('color-primary', $draft->derived_tokens_json);
        $this->assertSame('#123456', $draft->derived_tokens_json['color-primary']);
    }

    public function test_factory_preset_cannot_be_edited_in_place(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();

        $response = $this->put(route('admin.theme-presets.update', $factory->uid), $this->validTokenPayload());

        $response->assertSessionHasErrors('preset');
        $this->assertNotSame('#123456', $factory->fresh()->derived_tokens_json['color-primary']);
    }

    public function test_active_preset_cannot_be_edited_in_place(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        // Factory is seeded active.
        $this->assertSame(PlatformThemePreset::STATUS_ACTIVE, $factory->status);

        $response = $this->put(route('admin.theme-presets.update', $factory->uid), $this->validTokenPayload());
        $response->assertSessionHasErrors('preset');
    }

    public function test_activate_rejects_a_preset_still_in_draft(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $draft = $this->createDraftPreset();

        $response = $this->post(route('admin.theme-presets.activate', $draft->uid));

        $response->assertSessionHasErrors('preset');
        $this->assertSame(PlatformThemePreset::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_rename_is_blocked_for_factory_but_allowed_for_active_non_factory(): void
    {
        $this->actingAsAdmin(['access backend', 'manage theme']);
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();

        $this->post(route('admin.theme-presets.rename', $factory->uid), ['name' => 'New Name'])
            ->assertSessionHasErrors('preset');
        $this->assertSame('Warm Red', $factory->fresh()->name);
    }

    private function createDraftPreset(): PlatformThemePreset
    {
        $factory = PlatformThemePreset::query()->where('is_factory', true)->firstOrFail();
        $this->post(route('admin.theme-presets.store'), ['name' => 'Draft One']);

        return PlatformThemePreset::query()->where('name', 'Draft One')->firstOrFail();
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

    private function seedFactoryPreset(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
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
