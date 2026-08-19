<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Design System M2 Slice 1 contract §4.6/§9 item 24. Duplication needs
 * no client input — PlatformThemeManager::duplicate() derives a unique
 * "<name> copy" name automatically from the source preset (including
 * Factory, which may be duplicated though never edited in place).
 */
class DuplicatePlatformThemePresetRequest extends FormRequest
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
        return [];
    }
}
