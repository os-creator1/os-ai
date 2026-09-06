<?php

namespace App\Library\Automation\Actions;

use App\Library\Automation\AutomationActionResult;
use App\Models\Automation;
use App\Models\AutomationExecution;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * B4 Business Automations — UPDATE_CONTACT_FIELD (contract §7.B). Sets one
 * Business-scoped custom-field value on the TRIGGER Contact only (the
 * target is never configurable, which structurally removes cross-Business
 * target tampering). The field must belong to the Contact's own group /
 * the automation's Business — re-verified here at action time, never
 * trusted from definition time.
 *
 * Bounded: an explicit allowlisted {field_id, value} pair; no dynamic
 * attribute names, no arbitrary model/column assignment. The phone field
 * (`is_phone`) is never writable through this action — it is the Contact's
 * identity, not a custom attribute.
 *
 * DB-only, but it takes the same durable claim path as a provider send
 * (§5.3): the claim is recorded once, the write is naturally idempotent.
 */
class UpdateContactFieldAction
{
    public function run(AutomationExecution $execution, Automation $automation, Business $business, Contacts $contact): AutomationActionResult
    {
        $config = $automation->action_config ?? [];
        $fieldId = isset($config['field_id']) ? (int) $config['field_id'] : null;
        $value = array_key_exists('value', $config) ? (string) $config['value'] : null;

        if ($fieldId === null || $value === null) {
            return AutomationActionResult::skipped('field_config_invalid');
        }

        $field = ContactGroupFields::query()->with('contactGroup')->find($fieldId);

        if (
            $field === null
            || $field->contactGroup === null
            || (int) $field->contact_group_id !== (int) $contact->group_id
            || (int) $field->contactGroup->business_id !== (int) $business->id
        ) {
            return AutomationActionResult::skipped('field_not_in_contact_business');
        }

        if ($field->is_phone) {
            return AutomationActionResult::skipped('phone_field_not_writable');
        }

        $value = mb_substr($value, 0, 255);

        try {
            DB::transaction(function () use ($contact, $field, $value): void {
                ContactsCustomField::query()->updateOrCreate(
                    ['contact_id' => $contact->id, 'field_id' => $field->id],
                    ['value' => $value],
                );
            });
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('field_write_exception: ' . get_class($exception));
        }

        return AutomationActionResult::succeeded('Updated field "' . $field->label . '"');
    }
}
