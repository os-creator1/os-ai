<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessIndustry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * May update identity/contact/profile fields only — never customer_id,
 * is_primary, canonical_domain, status, or activated_at (RFC-001 §17).
 * Those protections are enforced by BusinessRepository, not this request.
 */
class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'industry' => ['required', new Enum(BusinessIndustry::class)],
            'industry_other' => ['nullable', 'string', 'max:255', 'required_if:industry,' . BusinessIndustry::Other->value],
            'description' => ['nullable', 'string', 'max:5000'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'google_business_profile_url' => ['nullable', 'string', 'max:2048'],
            'facebook_url' => ['nullable', 'string', 'max:2048'],
            'instagram_url' => ['nullable', 'string', 'max:2048'],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'currency_code' => ['required', 'string', 'size:3'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->filled('country_code') ? strtoupper((string) $this->input('country_code')) : $this->input('country_code'),
            'currency_code' => $this->filled('currency_code') ? strtoupper((string) $this->input('currency_code')) : $this->input('currency_code'),
        ]);

        foreach (['name', 'industry_other', 'description', 'email', 'phone', 'website_url', 'google_business_profile_url', 'facebook_url', 'instagram_url', 'timezone'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }
}
