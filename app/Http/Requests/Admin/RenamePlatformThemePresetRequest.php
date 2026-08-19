<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Design System M2 Slice 1 contract §4.6/§9 item 25. Renaming is
 * permitted on the active preset (metadata, not theme content) but
 * rejected for Factory by PlatformThemeManager::rename() (§6.1).
 */
class RenamePlatformThemePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage theme');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('platform_theme_presets', 'name')->ignore($this->route('preset')?->id),
            ],
        ];
    }
}
