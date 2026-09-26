<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * The client owner's own draft-activation form (see
 * BusinessManager::activateClientBusiness()). Deliberately combines the
 * identity fields UpsertBusinessIdentityRequest validates and the primary
 * location fields UpsertBusinessLocationRequest validates into one request:
 * this is a single confirmation step over the exact facts
 * AgencyClientProvisioningManager::accept() placeholdered (industry,
 * country, timezone, currency, primary location), not a general-purpose
 * Business editor. `confirm` is required and must be accepted — the
 * explicit acknowledgement that proves genuine review took place, the same
 * pattern AgencyClientsController's own consent actions already use.
 */
class ActivateClientBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'industry' => ['required', new Enum(BusinessIndustry::class)],
            'industry_other' => ['nullable', 'string', 'max:255', 'required_if:industry,' . BusinessIndustry::Other->value],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'currency_code' => ['required', 'string', 'size:3'],

            'location_name' => ['nullable', 'string', 'max:255'],
            'service_mode' => ['required', new Enum(BusinessServiceMode::class)],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'location_country_code' => ['required', 'string', 'size:2'],
            'public_address' => ['required', 'boolean'],
            'service_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'confirm' => ['required', 'accepted'],
        ];
    }

    /**
     * Mirrors UpsertBusinessLocationRequest::withValidator() exactly: a
     * storefront or hybrid location needs a real street address; a
     * service-area location needs at least a city/region.
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
            'currency_code' => $this->filled('currency_code') ? strtoupper((string) $this->input('currency_code')) : $this->input('currency_code'),
            'location_country_code' => $this->filled('location_country_code') ? strtoupper((string) $this->input('location_country_code')) : $this->input('location_country_code'),
            'public_address' => $this->boolean('public_address'),
        ]);

        foreach (['industry_other', 'location_name'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function identityAttributes(): array
    {
        return $this->only(['industry', 'industry_other', 'country_code', 'timezone', 'currency_code']);
    }

    /**
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        return [
            'name' => $this->input('location_name'),
            'service_mode' => $this->input('service_mode'),
            'address_line_1' => $this->input('address_line_1'),
            'address_line_2' => $this->input('address_line_2'),
            'city' => $this->input('city'),
            'region' => $this->input('region'),
            'postal_code' => $this->input('postal_code'),
            'country_code' => $this->input('location_country_code'),
            'public_address' => $this->boolean('public_address'),
            'service_radius_km' => $this->input('service_radius_km'),
        ];
    }
}
