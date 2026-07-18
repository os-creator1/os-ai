<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessServiceMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpsertBusinessLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'service_mode' => ['required', new Enum(BusinessServiceMode::class)],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['required', 'string', 'size:2'],
            'public_address' => ['required', 'boolean'],
            'service_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'service_area_cities' => ['nullable', 'array', 'max:50'],
            'service_area_cities.*' => ['string', 'max:120', 'distinct'],
        ];
    }

    /**
     * Conditional requirements by service mode (RFC-001 §17).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('service_mode');

            if (in_array($mode, [BusinessServiceMode::Storefront->value, BusinessServiceMode::Hybrid->value], true)) {
                foreach (['address_line_1', 'city', 'region'] as $field) {
                    if (! $this->filled($field)) {
                        $validator->errors()->add($field, __('The :attribute field is required.', ['attribute' => $field]));
                    }
                }
            } elseif ($mode === BusinessServiceMode::ServiceArea->value) {
                foreach (['city', 'region'] as $field) {
                    if (! $this->filled($field)) {
                        $validator->errors()->add($field, __('The :attribute field is required.', ['attribute' => $field]));
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('service_mode') === BusinessServiceMode::Online->value) {
            $this->merge(['service_radius_km' => null]);
        }

        $this->merge([
            'country_code' => $this->filled('country_code') ? strtoupper((string) $this->input('country_code')) : $this->input('country_code'),
            // An unchecked HTML checkbox simply isn't submitted; default it to false
            // so the required/boolean rule doesn't reject an intentional "no".
            'public_address' => $this->boolean('public_address'),
        ]);
    }
}
