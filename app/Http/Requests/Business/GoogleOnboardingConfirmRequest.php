<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceMode;
use App\Library\Business\BusinessAddressRules;
use App\Library\Business\OnboardingManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The confirmation of the Google listing card. It enforces, in one place, the
 * same rules the Business, Location, Services and Website step requests
 * enforce one by one — so a value that would be rejected in the manual wizard
 * is rejected here with the same wording, and an essential answer the card
 * still lacks is asked for rather than invented. Optional details (phone,
 * description, postal code, ...) are never required.
 */
class GoogleOnboardingConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'snapshot' => ['required', 'string'],

            'name' => ['required', 'string', 'min:2', 'max:255'],
            'industry' => ['required', new Enum(BusinessIndustry::class)],
            'industry_other' => ['nullable', 'string', 'max:255', 'required_if:industry,' . BusinessIndustry::Other->value],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'currency_code' => ['required', 'string', 'size:3'],

            'service_mode' => ['required', new Enum(BusinessServiceMode::class)],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'public_address' => ['nullable', 'boolean'],

            'main_service' => ['required', 'string', 'max:255'],

            'website_choice' => ['nullable', Rule::in(OnboardingManager::WEBSITE_CHOICES)],
            'website_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode = $this->input('service_mode');
            $required = match ($mode) {
                BusinessServiceMode::Storefront->value, BusinessServiceMode::Hybrid->value => ['address_line_1', 'city'],
                BusinessServiceMode::ServiceArea->value => ['city'],
                default => [],
            };

            if ($required !== [] && BusinessAddressRules::regionRequired((string) $this->input('country_code'))) {
                $required[] = 'region';
            }

            foreach ($required as $field) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, match ($field) {
                        'address_line_1' => 'Enter your street address.',
                        'city' => 'Enter your main city.',
                        default => 'Enter your state or region.',
                    });
                }
            }

            if ($this->input('website_choice') === 'existing' && ! $this->filled('website_url')) {
                $validator->errors()->add('website_url', 'Enter your website address, or choose another option.');
            }
        });
    }

    /** A validation failure returns to the card itself (a GET), never to the POST that produced it. */
    protected function getRedirectUrl(): string
    {
        return route('customer.onboarding.google.review');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->filled('country_code') ? strtoupper((string) $this->input('country_code')) : null,
            'currency_code' => $this->filled('currency_code') ? strtoupper((string) $this->input('currency_code')) : null,
        ]);

        foreach (['name', 'industry_other', 'description', 'phone', 'address_line_1', 'city', 'region', 'postal_code', 'main_service', 'website_url', 'timezone'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }

        // A custom niche description belongs only to the "Other" industry.
        if ((string) $this->input('industry') !== BusinessIndustry::Other->value) {
            $this->merge(['industry_other' => null]);
        }

        // A street address only applies to a place customers visit.
        $visited = in_array($this->input('service_mode'), ['storefront', 'hybrid'], true);

        $this->merge([
            'public_address' => $visited && $this->filled('address_line_1') && $this->boolean('public_address'),
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your business name.',
            'name.min' => 'Your business name needs at least 2 characters.',
            'industry.required' => 'Choose the kind of business you run.',
            'industry.enum' => 'Choose one of the listed kinds of business.',
            'industry_other.required_if' => 'Tell us what kind of business this is.',
            'country_code.required' => 'Choose your country.',
            'timezone.required' => 'Choose your timezone.',
            'timezone.timezone' => 'Choose one of the listed timezones.',
            'currency_code.required' => 'Choose your currency.',
            'service_mode.required' => 'Tell us how customers reach you.',
            'service_mode.enum' => 'Choose one of the listed options.',
            'main_service.required' => 'Confirm your main service, for example “Photo Booth Rental”.',
            'snapshot.required' => 'That import has expired. Please import your Google profile again.',
        ];
    }
}
