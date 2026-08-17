<?php

namespace Database\Seeders;

use App\Library\Theme\ThemeColorDerivationService;
use App\Models\PlatformThemePreset;
use App\Models\PlatformThemePresetEvent;
use Illuminate\Database\Seeder;

/**
 * Design System M2 Slice 1 contract §6.17/§9 item 11. Seeds the single,
 * permanent Factory preset ("Warm Red", active, is_factory=true) plus
 * three optional starter presets (Clear Red, Warm Brown, Dark Chocolate)
 * as status='saved', is_factory=false — convenient, fully-editable
 * starting points, never locked palettes (§4.6).
 */
class PlatformThemePresetSeeder extends Seeder
{
    private const STATUS_BASES = [
        'status_success' => '#28C76F',
        'status_warning' => '#FF9F43',
        'status_danger' => '#EA5455',
        'status_info' => '#00CFE8',
        'status_pending' => '#B58B46',
    ];

    private const CHART_SLOTS = [
        'chart_1' => '#B5524C',
        'chart_2' => '#647C78',
        'chart_3' => '#4E79A7',
        'chart_4' => '#59A14F',
        'chart_5' => '#F28E2B',
        'chart_6' => '#B07AA1',
        'chart_7' => '#EDC948',
        'chart_8' => '#76B7B2',
        'chart_neutral' => '#A8A29A',
    ];

    public function run(): void
    {
        if (PlatformThemePreset::query()->where('is_factory', true)->exists()) {
            return;
        }

        $derivationService = app(ThemeColorDerivationService::class);

        $factoryInputs = array_merge([
            'primary' => '#B5524C',
            'secondary' => '#8A6F67',
            'canvas' => '#F7F6F2',
            'sidebar' => '#F2F0EB',
            'surface' => '#FFFFFF',
            'surface_secondary' => '#FBFAF7',
            'input' => '#FFFFFF',
            'border' => '#E5E1DA',
            'header' => null,
        ], self::STATUS_BASES, self::CHART_SLOTS);

        $factory = PlatformThemePreset::query()->create([
            'name' => 'Warm Red',
            'status' => PlatformThemePreset::STATUS_ACTIVE,
            'is_factory' => true,
            'input_tokens_json' => $factoryInputs,
            'overrides_json' => null,
            'derived_tokens_json' => $derivationService->deriveFullTokenSet($factoryInputs),
            'font_choice' => PlatformThemePreset::FONT_CHOICE_BUNDLED,
            'font_bundled_key' => 'geist',
            'font_uploaded_id' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        $this->recordCreated($factory->id);

        $clearRedInputs = array_merge($factoryInputs, ['primary' => '#C74444']);
        $clearRed = PlatformThemePreset::query()->create([
            'name' => 'Clear Red',
            'status' => PlatformThemePreset::STATUS_SAVED,
            'is_factory' => false,
            'input_tokens_json' => $clearRedInputs,
            'overrides_json' => null,
            'derived_tokens_json' => $derivationService->deriveFullTokenSet($clearRedInputs),
            'font_choice' => PlatformThemePreset::FONT_CHOICE_BUNDLED,
            'font_bundled_key' => 'geist',
            'font_uploaded_id' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);
        $this->recordCreated($clearRed->id);

        $warmBrownInputs = array_merge($factoryInputs, ['primary' => '#936047']);
        $warmBrown = PlatformThemePreset::query()->create([
            'name' => 'Warm Brown',
            'status' => PlatformThemePreset::STATUS_SAVED,
            'is_factory' => false,
            'input_tokens_json' => $warmBrownInputs,
            'overrides_json' => null,
            'derived_tokens_json' => $derivationService->deriveFullTokenSet($warmBrownInputs),
            'font_choice' => PlatformThemePreset::FONT_CHOICE_BUNDLED,
            'font_bundled_key' => 'geist',
            'font_uploaded_id' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);
        $this->recordCreated($warmBrown->id);

        // Base 'surface'/'input'/'border' are deliberately NOT the literal
        // "raised surface"/"secondary bubble"/"border-detail" swatches from
        // the contract's descriptive palette — those dark-on-dark tones
        // fail the §6.7 hard-reject gates (border-vs-surface, focus-ring-
        // vs-surface, focus-border-vs-input-bg at >=3:1). Canvas/sidebar/
        // hover/text/muted-text keep the exact contract hex; the literal
        // raised-surface/incoming-bubble/border-detail swatches are still
        // honored, but as non-gated decorative overrides below.
        $darkChocolateInputs = array_merge($factoryInputs, [
            'primary' => '#A83E00',
            'canvas' => '#140500',
            'sidebar' => '#0E0301',
            'surface' => '#0E0301',
            'surface_secondary' => '#21100C',
            'input' => '#140500',
            'border' => '#9C5744',
        ]);
        $darkChocolateDerived = $derivationService->deriveFullTokenSet($darkChocolateInputs);
        $darkChocolateOverrides = [
            'color-primary-hover' => '#C24B00',
            'color-text-primary' => '#FFF8F5',
            'color-text-muted' => '#BEB5B2',
            'color-chat-bubble-in' => '#492723',
            'color-chat-composer' => '#673D3A',
            'color-border-strong' => '#5D2C26',
        ];
        $darkChocolate = PlatformThemePreset::query()->create([
            'name' => 'Dark Chocolate',
            'status' => PlatformThemePreset::STATUS_SAVED,
            'is_factory' => false,
            'input_tokens_json' => $darkChocolateInputs,
            'overrides_json' => $darkChocolateOverrides,
            'derived_tokens_json' => array_merge($darkChocolateDerived, $darkChocolateOverrides),
            'font_choice' => PlatformThemePreset::FONT_CHOICE_BUNDLED,
            'font_bundled_key' => 'geist',
            'font_uploaded_id' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);
        $this->recordCreated($darkChocolate->id);
    }

    private function recordCreated(int $presetId): void
    {
        $event = new PlatformThemePresetEvent([
            'preset_id' => $presetId,
            'event_type' => PlatformThemePresetEvent::EVENT_CREATED,
            'metadata_json' => ['source' => 'seeder'],
            'actor_user_id' => null,
        ]);
        $event->created_at = now();
        $event->save();
    }
}
