<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Automations V2 — the Update contact field action.
 *
 * The semantics are B4's `UpdateContactFieldAction` (§7.B), deliberately
 * unchanged rather than reimplemented: one allowlisted {field_id, value} pair,
 * written to the ENROLLED contact only, through the same `contacts_custom_fields`
 * upsert the rest of the product uses. No dynamic attribute names, no arbitrary
 * column assignment, no second mutation system.
 *
 * WHY THE TENANCY CHECK IS REPEATED HERE. WorkflowCompiler already proved, at
 * publish time, that `field_id` belongs to the trigger group and to this
 * Business. That check is necessary but not sufficient: a version is pinned and
 * long-lived, and a group can be moved, a field deleted and its id reused, or a
 * contact re-pointed at another group between publishing and this instant. So
 * the ownership chain is re-derived from real rows at action time —
 * field → group → business — and anything that no longer lines up is a skip, not
 * a write. Definition-time trust is never carried into a mutation.
 *
 * The phone field is never writable: it is the contact's identity, and phone
 * uniqueness is per group.
 *
 * Side-effect class IdempotentDatabase: the write sets a value to its configured
 * value, so re-applying it after an interrupted step is harmless — which is
 * exactly why recovery is allowed to re-derive this step but never a send.
 */
class UpdateContactFieldNodeExecutor implements NodeExecutor
{
    /** The column's own limit; the registry caps configured values at 255 too. */
    private const MAX_VALUE_LENGTH = 255;

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::UpdateContactField;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];

        $fieldId = $this->positiveInt($config['field_id'] ?? null);
        $value = array_key_exists('value', $config) && is_string($config['value'])
            ? $config['value']
            : null;

        if ($fieldId === null || $value === null) {
            return NodeExecutionOutcome::skipped('field_config_invalid');
        }

        $field = ContactGroupFields::query()->with('contactGroup')->find($fieldId);

        // The full ownership chain, re-derived now. The contact was already
        // proven to be in this Business by the checkpoint; the field must be in
        // the contact's OWN group, and that group must belong to the same
        // Business — so a field id pointing anywhere else, including at another
        // Business, writes nothing.
        if (
            $field === null
            || $field->contactGroup === null
            || (int) $field->contact_group_id !== (int) $contact->group_id
            || (int) $field->contactGroup->business_id !== (int) $business->id
        ) {
            return NodeExecutionOutcome::skipped('field_not_in_contact_business');
        }

        if ($field->is_phone) {
            return NodeExecutionOutcome::skipped('phone_field_not_writable');
        }

        $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH);

        try {
            DB::transaction(function () use ($contact, $field, $value): void {
                ContactsCustomField::query()->updateOrCreate(
                    ['contact_id' => $contact->id, 'field_id' => $field->id],
                    ['value' => $value],
                );
            });
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('field_write_exception: ' . class_basename($exception));
        }

        return NodeExecutionOutcome::succeeded('Updated field "' . $field->label . '"');
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }
}
