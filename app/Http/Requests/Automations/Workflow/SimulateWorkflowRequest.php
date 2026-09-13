<?php

namespace App\Http\Requests\Automations\Workflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Automations V2-E — "Test workflow" for one contact.
 *
 * The contact is named by uid and nothing else. It is resolved by the controller
 * strictly inside the already-resolved Business, so a uid belonging to another
 * Business is a 404 exactly like one that does not exist — this request does not,
 * and must not, look the contact up itself.
 */
class SimulateWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations') ?? false;
    }

    public function rules(): array
    {
        return [
            'contact_uid' => ['required', 'string', 'max:64'],
        ];
    }
}
