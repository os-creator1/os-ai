<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidFontFileRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Design System M2 Slice 1 contract §6.9/§9 item 28. Extension/MIME
 * whitelist here, magic-byte content validation via ValidFontFileRule —
 * neither check alone is sufficient (§6.9).
 */
class UploadPlatformThemeFontRequest extends FormRequest
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
            'font' => ['required', 'file', 'mimes:woff,woff2', 'max:5120', new ValidFontFileRule()],
        ];
    }
}
