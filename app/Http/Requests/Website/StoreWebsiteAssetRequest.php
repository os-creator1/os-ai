<?php

namespace App\Http\Requests\Website;

use App\Rules\ValidWebsiteImageRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Website Generation + Hosting Slice A contract §13. The FormRequest-
 * layer magic-byte/dimension check — deliberately in addition to, not
 * instead of, WebsiteAssetUploadService's own independent re-validation.
 *
 * `alt_text` is required: an uploaded photo is only ever useful once it
 * has a real description, both for visitors using a screen reader and
 * for the owner picking a photo out of a list later.
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
            'alt_text' => ['required', 'string', 'max:160'],
        ];
    }
}
