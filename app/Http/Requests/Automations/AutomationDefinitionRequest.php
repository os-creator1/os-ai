<?php

namespace App\Http\Requests\Automations;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B4 Business Automations — shape-level validation only for the bounded
 * create/edit form (contract §14). The `automations` capability check
 * here is NOT tenant authorization (§16): the controller still performs
 * the full Workspace → Business → userCanAccessBusiness → entitlement
 * chain, and the per-type trigger/action configuration is re-resolved
 * against the explicit Business by AutomationDefinitionValidator — never
 * mass-assigned from this request (§12.3).
 */
class AutomationDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('automations');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_type' => ['required', Rule::in(array_map(fn (AutomationTriggerType $t) => $t->value, AutomationTriggerType::cases()))],
            'action_type' => ['required', Rule::in(array_map(fn (AutomationActionType $a) => $a->value, AutomationActionType::cases()))],
            'enabled' => ['nullable', 'boolean'],

            // Trigger configuration (validated per type by the validator).
            'contact_group_id' => ['nullable', 'integer'],
            'date_field_id' => ['nullable', 'integer'],
            'offset' => ['nullable', 'string', 'max:20'],
            'send_at' => ['nullable', 'string', 'max:5'],

            // Action configuration (validated per type by the validator).
            'sms_type' => ['nullable', 'string', 'max:10'],
            'message' => ['nullable', 'string', 'max:1600'],
            'sender_id' => ['nullable', 'string', 'max:64'],
            'sending_server' => ['nullable', 'integer'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'field_id' => ['nullable', 'integer'],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
