<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Connect Google Business Profile". The locale fields are only ever needed
 * when there is no Draft Business to continue (OnboardingManager enforces
 * that, and never defaults them); here they are only format-checked.
 */
class GoogleOnboardingStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'country_code' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'address_consent' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->filled('country_code') ? strtoupper((string) $this->input('country_code')) : null,
            'currency_code' => $this->filled('currency_code') ? strtoupper((string) $this->input('currency_code')) : null,
            'timezone' => $this->filled('timezone') ? (string) $this->input('timezone') : null,
            'address_consent' => $this->boolean('address_consent'),
        ]);
    }

    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Choose one of the listed timezones.',
            'country_code.size' => 'Choose your country.',
            'currency_code.size' => 'Choose your currency.',
        ];
    }
}
