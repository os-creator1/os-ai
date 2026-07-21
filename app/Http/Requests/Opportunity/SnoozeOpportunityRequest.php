<?php

namespace App\Http\Requests\Opportunity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accepts only a fixed duration choice — never a raw snoozed_until
 * timestamp, reason, or any tenant/workflow field. The controller maps the
 * validated key to a concrete instant server-side (OpportunityManager::
 * snooze() itself enforces no maximum duration, so this fixed set is a UI
 * decision, not a domain requirement).
 */
class SnoozeOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'duration' => ['required', Rule::in(['1_day', '3_days', '1_week'])],
        ];
    }

    public function messages(): array
    {
        return [
            'duration.required' => 'Choose how long to snooze this opportunity for.',
            'duration.in' => 'Choose how long to snooze this opportunity for.',
        ];
    }
}
