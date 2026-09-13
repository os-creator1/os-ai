<?php

namespace App\Http\Requests\Automations\Workflow;

use App\Library\Automation\Workflow\WorkflowLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Automations V2-E — enroll contacts by hand (§8.4, §20.2).
 *
 * Two guards from the contract, both enforced here rather than trusted to a UI:
 *
 *   A HARD CAP of WorkflowLimits::MAX_MANUAL_ENROLLMENTS_PER_REQUEST. One request
 *   that asks for more is REFUSED WHOLE, never truncated to the first 500 —
 *   silently enrolling some of a list is the kind of partial success nobody can
 *   diagnose afterwards.
 *
 *   EXPLICIT CONFIRMATION. Enrolling people starts journeys that can text them,
 *   so the request must carry `confirmed`; a replayed or scripted POST without it
 *   does nothing.
 *
 * Contact uids are only shape-checked here. Whether each one belongs to this
 * Business is decided by the controller against real rows, and a list that
 * names any other Business's contact is refused rather than partly honoured.
 */
class ManualEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations') ?? false;
    }

    public function rules(): array
    {
        return [
            'contact_uids' => [
                'required',
                'array',
                'min:1',
                'max:' . WorkflowLimits::MAX_MANUAL_ENROLLMENTS_PER_REQUEST,
            ],
            'contact_uids.*' => ['required', 'string', 'max:64', 'distinct'],
            'confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_uids.max' => sprintf(
                'You can enroll at most %d contacts at a time.',
                WorkflowLimits::MAX_MANUAL_ENROLLMENTS_PER_REQUEST,
            ),
            'confirmed.accepted' => 'Confirm that you want to enroll these contacts.',
        ];
    }
}
