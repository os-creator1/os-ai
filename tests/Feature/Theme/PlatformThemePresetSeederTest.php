<?php

namespace Tests\Feature\Theme;

use App\Models\PlatformThemePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.17/§9 item 77. Seeds exactly one
 * permanent Factory preset (active, is_factory) plus three starter
 * presets (saved, not factory); idempotent on re-run (§6.17: "seeded
 * once, never created a second time").
 */
class PlatformThemePresetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exactly_one_active_factory_preset(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);

        $factories = PlatformThemePreset::query()->where('is_factory', true)->get();
        $this->assertCount(1, $factories);
        $this->assertSame('Warm Red', $factories->first()->name);
        $this->assertSame(PlatformThemePreset::STATUS_ACTIVE, $factories->first()->status);
        $this->assertSame(PlatformThemePreset::FONT_CHOICE_BUNDLED, $factories->first()->font_choice);
        $this->assertSame('geist', $factories->first()->font_bundled_key);
    }

    public function test_seeder_creates_three_saved_non_factory_starter_presets(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);

        foreach (['Clear Red', 'Warm Brown', 'Dark Chocolate'] as $name) {
            $preset = PlatformThemePreset::query()->where('name', $name)->first();
            $this->assertNotNull($preset, "Starter preset \"{$name}\" was not seeded.");
            $this->assertSame(PlatformThemePreset::STATUS_SAVED, $preset->status);
            $this->assertFalse($preset->is_factory);
        }

        $this->assertSame(4, PlatformThemePreset::query()->count());
    }

    public function test_seeder_is_idempotent_and_never_creates_a_second_factory_row(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);

        $this->assertSame(1, PlatformThemePreset::query()->where('is_factory', true)->count());
        $this->assertSame(4, PlatformThemePreset::query()->count());
    }

    public function test_every_seeded_preset_has_a_unique_uid(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);

        $uids = PlatformThemePreset::query()->pluck('uid');
        $this->assertCount($uids->count(), $uids->unique());
        $this->assertTrue($uids->every(fn ($uid) => is_string($uid) && strlen($uid) > 0));
    }

    public function test_dark_chocolate_preset_passes_contrast_validation(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PlatformThemePresetSeeder']);

        $darkChocolate = PlatformThemePreset::query()->where('name', 'Dark Chocolate')->firstOrFail();
        $result = app(\App\Library\Theme\ThemeContrastValidator::class)->validate($darkChocolate->derived_tokens_json);

        $this->assertEmpty($result['errors'], 'Dark Chocolate must pass every hard-reject contrast check: ' . implode(' ', $result['errors']));
    }
}
