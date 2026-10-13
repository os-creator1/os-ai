<?php

namespace App\Http\Requests\Business;

/**
 * Onboarding step 2: the standard primary-location rules (storefront and
 * hybrid need an address, a service-area business only a city and region,
 * online-only only a country) plus a plain-text "other cities" box that
 * is split into the canonical service_area_cities list.
 */
class OnboardingLocationRequest extends UpsertBusinessLocationRequest
{
    public function messages(): array
    {
        return [
            'service_mode.required' => 'Tell us how your business operates.',
            'service_mode.enum' => 'Choose one of the listed options.',
            'country_code.required' => 'Choose your country.',
            'city.required' => 'Enter your main city.',
            'region.required' => 'Enter your state or region.',
            'address_line_1.required' => 'Enter your street address.',
            'service_radius_km.integer' => 'Enter the service radius as a whole number of kilometres.',
            'service_area_cities.max' => 'List up to 50 additional cities.',
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $cities = $this->input('service_area_cities');

        if (is_string($cities)) {
            $cities = preg_split('/[\r\n,;]+/', $cities) ?: [];
        }

        if (is_array($cities)) {
            $seen = [];

            foreach ($cities as $city) {
                $city = trim((string) $city);

                if ($city !== '' && ! isset($seen[mb_strtolower($city)])) {
                    $seen[mb_strtolower($city)] = $city;
                }
            }

            $this->merge(['service_area_cities' => array_values($seen) ?: null]);
        }

        foreach (['name', 'address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'service_radius_km'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }

        // The wizard's physical-address fields only apply to a place customers visit.
        if (in_array($this->input('service_mode'), ['service_area', 'online'], true) && ! $this->filled('address_line_1')) {
            $this->merge(['public_address' => false]);
        }
    }
}
