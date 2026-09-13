<?php

namespace App\Http\Requests\Automations\Workflow;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Automations V2-E — create a workflow.
 *
 * SHAPE ONLY. Whether this Business may create one at all is the controller's
 * tenancy chain; the starting document, including the trigger's own default
 * enrollment rule, is WorkflowDraftService's. This request only refuses a
 * trigger nothing in the product can report yet — `message_received` belongs to
 * V2-F — so a workflow can never be created around a start that will never fire.
 */
class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations') ?? false;
    }

    public function rules(): array
    {
        $available = array_values(array_map(
            static fn (WorkflowTriggerType $type): string => $type->value,
            array_filter(
                WorkflowTriggerType::cases(),
                static fn (WorkflowTriggerType $type): bool => $type->isIngestableInThisSlice(),
            ),
        ));

        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', 'string', Rule::in($available)],
        ];
    }
}
