<?php

namespace App\Http\Requests\Website;

use App\Rules\ValidWebsiteImageRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Website Generation + Hosting Slice A contract §13. The FormRequest-
 * layer magic-byte/dimension check — deliberately in addition to, not
 * instead of, WebsiteAssetUploadService's own independent re-validation.
 */
class StoreWebsiteAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', new ValidWebsiteImageRule()],
            'alt_text' => ['nullable', 'string', 'max:160'],
        ];
    }
}
