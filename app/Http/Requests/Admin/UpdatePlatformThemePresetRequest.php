<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Design System M2 Slice 1 contract §6.5/§6.8/§9 item 22. Simple-mode
 * base fields plus an optional Advanced-mode `overrides` map. Every
 * field is a fixed, named hex-string or enum — no free-text/CSS field
 * exists anywhere in this schema (§6.8).
 */
class UpdatePlatformThemePresetRequest extends FormRequest
{
    /**
     * The complete set of derived-token keys eligible for Advanced-mode
     * override — must exactly match ThemeColorDerivationService's own
     * deriveFullTokenSet() output keys (§6.6). Public so the theme
     * editor view can render the same list without duplicating it.
     */
    public const OVERRIDABLE_KEYS = [
        'color-primary', 'color-primary-hover', 'color-primary-active', 'color-primary-soft-bg', 'color-primary-border',
        'color-secondary', 'color-secondary-hover', 'color-secondary-active', 'color-secondary-soft-bg',
        'color-accent', 'color-link', 'color-link-hover', 'color-focus-ring', 'color-selection',
        'color-button-primary-bg', 'color-button-secondary-bg', 'color-button-text',
        'color-nav-hover', 'color-nav-active',
        'color-text-primary', 'color-text-secondary', 'color-text-muted', 'color-text-inverse', 'color-text-disabled',
        'color-icon-default', 'color-icon-muted', 'color-icon-inverse',
        'color-canvas', 'color-sidebar', 'color-header', 'color-surface', 'color-surface-secondary', 'color-dropdown',
        'color-input-bg', 'color-table-header', 'color-table-row', 'color-row-hover',
        'color-chat-canvas', 'color-chat-bubble-in', 'color-chat-bubble-out', 'color-chat-composer', 'color-modal-surface',
        'color-overlay', 'color-tooltip-surface', 'color-tooltip-text', 'color-popover-surface',
        'color-border', 'color-border-subtle', 'color-border-strong', 'color-input-border', 'color-active-border',
        'color-focus-border', 'color-shadow-tint', 'color-elevated-shadow-tint',
        'color-chart-1', 'color-chart-2', 'color-chart-3', 'color-chart-4',
        'color-chart-5', 'color-chart-6', 'color-chart-7', 'color-chart-8',
        'color-chart-neutral', 'color-chart-grid', 'color-chart-axis',
        'color-chart-tooltip-bg', 'color-chart-tooltip-text', 'color-chart-positive', 'color-chart-negative',
    ];

    private const HEX_PATTERN = '/^#[0-9A-Fa-f]{6}$/';
    private const ALPHA_PATTERN = '/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*(0|1|0?\.\d+)\s*\)$/';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage theme');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $hex = ['required', 'regex:' . self::HEX_PATTERN];
        $hexNullable = ['nullable', 'regex:' . self::HEX_PATTERN];

        $rules = [
            'primary' => $hex,
            'secondary' => $hex,
            'canvas' => $hex,
            'sidebar' => $hex,
            'surface' => $hex,
            'surface_secondary' => $hex,
            'input' => $hex,
            'border' => $hex,
            'header' => $hexNullable,
            'status_success' => $hex,
            'status_warning' => $hex,
            'status_danger' => $hex,
            'status_info' => $hex,
            'status_pending' => $hex,
            'chart_neutral' => $hexNullable,
            'font_choice' => ['required', Rule::in(['bundled', 'uploaded'])],
            'font_bundled_key' => ['nullable', 'required_if:font_choice,bundled', Rule::in(['geist', 'system-ui'])],
            'font_uploaded_id' => ['nullable', 'required_if:font_choice,uploaded', 'integer', 'exists:platform_theme_fonts,id'],
            'overrides' => ['nullable', 'array'],
        ];

        for ($i = 1; $i <= 8; $i++) {
            $rules["chart_{$i}"] = $hex;
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $overrides = $this->input('overrides', []);
            if (! is_array($overrides)) {
                return;
            }

            foreach ($overrides as $key => $value) {
                if (! in_array($key, self::OVERRIDABLE_KEYS, true)) {
                    $validator->errors()->add("overrides.{$key}", "\"{$key}\" is not an overridable theme token.");

                    continue;
                }

                if (! is_string($value) || (! preg_match(self::HEX_PATTERN, $value) && ! preg_match(self::ALPHA_PATTERN, $value))) {
                    $validator->errors()->add("overrides.{$key}", "The override value for \"{$key}\" must be a valid hex or rgba color.");
                }
            }
        });
    }
}
