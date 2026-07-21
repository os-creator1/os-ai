<?php

namespace App\Http\Requests\Opportunity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates only the single generic `value` parameter OpportunityManager::
 * configureAction() accepts (RFC-002 §13.1, §28, §45) — every registry-owned
 * field (action_key, approval_required, completion_policy, schema_version,
 * recommended_action_hash) and every tenant/workflow field (business_id,
 * occurrence_number, status, freshness) is never defined here, so none can
 * ever reach the controller through validated().
 */
class ConfigureOpportunityActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:50'],
        ];
    }
}
