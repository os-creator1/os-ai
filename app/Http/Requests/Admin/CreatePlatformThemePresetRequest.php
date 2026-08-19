<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Design System M2 Slice 1 contract §4.6/§9 item 23. Creates a new
 * Draft preset starting from the Factory preset's current tokens/font —
 * the owner edits and saves it via UpdatePlatformThemePresetRequest.
 */
class CreatePlatformThemePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage theme');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:platform_theme_presets,name'],
        ];
    }
}
