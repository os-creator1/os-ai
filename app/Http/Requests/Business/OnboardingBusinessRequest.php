<?php

namespace App\Http\Requests\Business;

use Illuminate\Support\Arr;

/**
 * Onboarding step 1. The same identity rules as everywhere else, minus the
 * website address: that question belongs to the Website step, and a field
 * that is not validated here can never be written by this step.
 */
class OnboardingBusinessRequest extends UpsertBusinessIdentityRequest
{
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['website_url']);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your business name.',
            'name.min' => 'Your business name needs at least 2 characters.',
            'industry.required' => 'Choose the kind of business you run.',
            'industry.enum' => 'Choose one of the listed kinds of business.',
            'industry_other.required_if' => 'Tell us what kind of business this is.',
            'email.email' => 'Enter a valid email address, or leave it blank.',
            'country_code.required' => 'Choose your country.',
            'timezone.required' => 'Choose your timezone.',
            'timezone.timezone' => 'Choose one of the listed timezones.',
            'currency_code.required' => 'Choose your currency.',
        ];
    }
}
