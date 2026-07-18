<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'website_url' => ['nullable', 'string', 'max:2048'],
            'google_business_profile_url' => ['nullable', 'string', 'max:2048'],
            'facebook_url' => ['nullable', 'string', 'max:2048'],
            'instagram_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['website_url', 'google_business_profile_url', 'facebook_url', 'instagram_url'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
