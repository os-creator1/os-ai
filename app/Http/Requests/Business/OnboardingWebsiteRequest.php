<?php

namespace App\Http\Requests\Business;

use App\Library\ExternalSite\ExternalSiteException;
use App\Library\Website\WebsiteModeManager;
use App\Library\Business\OnboardingManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding step 4. One question with three answers; only "I already have a
 * website" needs an address, and it must be a plausible public one.
 */
class OnboardingWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'website_choice' => ['required', Rule::in(OnboardingManager::WEBSITE_CHOICES)],
            'website_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'website_choice.required' => 'Choose how you would like to handle your website.',
            'website_choice.in' => 'Choose one of the listed options.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('website_choice') !== 'existing' || $validator->errors()->has('website_url')) {
                return;
            }

            if (! $this->filled('website_url')) {
                $validator->errors()->add('website_url', 'Enter your website address, for example example.com.');

                return;
            }

            try {
                app(WebsiteModeManager::class)->normalizeAddress((string) $this->input('website_url'));
            } catch (ExternalSiteException) {
                $validator->errors()->add('website_url', 'Enter a valid public website address, for example example.com.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('website_url') && trim((string) $this->input('website_url')) === '') {
            $this->merge(['website_url' => null]);
        }
    }
}
