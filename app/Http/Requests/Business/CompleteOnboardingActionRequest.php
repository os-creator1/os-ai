<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOnboardingActionRequest extends FormRequest
{
    /**
     * Mirrors OnboardingActionExecutor's allowlist (RFC-001 §14) — kept as an
     * explicit closed list here too, rather than importing the executor's
     * constant, so this request never silently drifts if that list changes.
     */
    private const ALLOWED_ACTIONS = [
        'add_phone',
        'add_email',
        'add_website',
        'add_description',
        'add_location',
        'complete_location',
        'add_service',
        'confirm_primary_service',
        'add_gbp_url',
        'add_facebook_url',
        'add_instagram_url',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'fingerprint' => ['required', 'string', 'max:255'],
            'action_key' => ['required', 'string', Rule::in(self::ALLOWED_ACTIONS)],
            'value' => ['nullable', 'string', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('value') && trim((string) $this->input('value')) === '') {
            $this->merge(['value' => null]);
        }
    }
}
