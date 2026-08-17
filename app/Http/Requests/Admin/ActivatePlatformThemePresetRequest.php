<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Design System M2 Slice 1 contract §6.14/§9 item 26. No client input —
 * the target preset comes entirely from the route binding. Also used
 * for "return to the previously active preset": mechanically the same
 * operation, PlatformThemeManager decides the resulting event type.
 */
class ActivatePlatformThemePresetRequest extends FormRequest
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
