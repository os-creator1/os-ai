<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B3 Simplified Platform Settings §4/§17 — the Appearance "Remove"
 * action. Wires the already-implemented, previously-uncalled
 * BrandingUploadService::delete() to exactly one allowlisted logical
 * asset key per request; the six values below are the only fields
 * BrandingUploadService::ENV_KEYS knows how to reset. No arbitrary
 * request-controlled env key or file path is ever accepted.
 */
class RemoveBrandingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('general settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'asset' => [
                'required',
                'string',
                Rule::in([
                    'app_logo',
                    'app_favicon',
                    'logo_compact',
                    'logo_dark',
                    'auth_illustration',
                    'installer_illustration',
                ]),
            ],
        ];
    }
}
