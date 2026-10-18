<?php

namespace App\Library\Forms\Builder;

use App\Enums\CustomFields\CustomFieldType;
use App\Enums\Forms\FormFieldType;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Models\Business;

/**
 * The visual builder's toolbox: the single server-side statement of which
 * elements an owner can add and what each starts as. The browser receives this
 * as data and never invents an element of its own — a dropped item becomes a
 * field with exactly these properties, and FormDefinitionNormalizer still has the
 * last word on whether it is valid.
 *
 * "Custom fields" lists ONLY the Business's ACTIVE canonical Custom Fields
 * (CustomFieldDefinitionManager — the same registry Settings → Custom fields
 * writes). The builder never defines a field of its own: an item here carries the
 * definition's stable `uid`, which is the explicit mapping ("Save answer to")
 * the submission uses. An archived definition is never offered for new
 * insertion; a version that already maps one keeps rendering and reading safely.
 *
 * There is deliberately NO Payments group: see docs/automation/FORMS-VISUAL-BUILDER-V1.md.
 */
final class FormToolbox
{
    public const TRANSACTIONAL_TEXT = 'I agree to receive messages about my request, such as confirmations and replies.';

    public const MARKETING_TEXT = 'I agree to receive promotional messages and offers. I can opt out at any time.';

    public function __construct(private readonly CustomFieldDefinitionManager $customFields)
    {
    }

    /**
     * @return list<array{id: string, label: string, items: list<array<string, mixed>>}>
     */
    public function groups(Business $business): array
    {
        $groups = [
            ['id' => 'quick', 'label' => 'Quick add', 'items' => [
                $this->item('full_name', 'Full name', 'user', $this->element(FormFieldType::Text, 'Full name', ['contact_name' => true, 'required' => true])),
                $this->item('email', 'Email', 'mail', $this->element(FormFieldType::Email, 'Email', ['required' => true])),
                $this->item('phone', 'Phone', 'phone', $this->element(FormFieldType::Phone, 'Phone', ['required' => true])),
                $this->item('short_text', 'Short text', 'type', $this->element(FormFieldType::Text, 'Short text')),
                $this->item('dropdown', 'Dropdown', 'chevron-down', $this->choice(FormFieldType::Select, 'Dropdown')),
            ]],
            ['id' => 'personal', 'label' => 'Personal info', 'items' => [
                $this->item('full_name', 'Full name', 'user', $this->element(FormFieldType::Text, 'Full name', ['contact_name' => true, 'required' => true])),
                $this->item('first_name', 'First name', 'user', $this->element(FormFieldType::Text, 'First name', ['contact_part' => 'first_name', 'width' => 'half', 'required' => true])),
                $this->item('last_name', 'Last name', 'user', $this->element(FormFieldType::Text, 'Last name', ['contact_part' => 'last_name', 'width' => 'half'])),
            ]],
            ['id' => 'contact', 'label' => 'Contact', 'items' => [
                $this->item('email', 'Email', 'mail', $this->element(FormFieldType::Email, 'Email', ['required' => true])),
                $this->item('phone', 'Phone', 'phone', $this->element(FormFieldType::Phone, 'Phone', ['required' => true])),
            ]],
            ['id' => 'fields', 'label' => 'Fields', 'items' => [
                $this->item('short_text', 'Short text', 'type', $this->element(FormFieldType::Text, 'Short text')),
                $this->item('long_text', 'Long text', 'align-left', $this->element(FormFieldType::Textarea, 'Long text')),
                $this->item('number', 'Number', 'hash', $this->element(FormFieldType::Number, 'Number')),
                $this->item('currency', 'Currency', 'dollar-sign', $this->element(FormFieldType::Currency, 'Amount')),
                $this->item('date', 'Date', 'calendar', $this->element(FormFieldType::Date, 'Date')),
                $this->item('datetime', 'Date & time', 'clock', $this->element(FormFieldType::DateTime, 'Date & time')),
            ]],
            ['id' => 'choice', 'label' => 'Choice', 'items' => [
                $this->item('dropdown', 'Dropdown', 'chevron-down', $this->choice(FormFieldType::Select, 'Dropdown')),
                $this->item('multi_select', 'Multi-select', 'check-square', $this->choice(FormFieldType::MultiSelect, 'Multi-select')),
                $this->item('checkbox', 'Checkbox', 'check', $this->element(FormFieldType::Checkbox, 'Checkbox')),
                $this->item('radio', 'Radio buttons', 'circle', $this->choice(FormFieldType::Radio, 'Radio buttons')),
                $this->item('yes_no', 'Yes / No', 'toggle-left', $this->element(FormFieldType::YesNo, 'Yes or no?')),
                // File upload: deliberately absent (needs a storage + malware-scan + retention design of its own).
            ]],
            ['id' => 'content', 'label' => 'Content', 'items' => [
                $this->item('heading', 'Heading', 'heading', $this->element(FormFieldType::Heading, 'Heading')),
                $this->item('paragraph', 'Paragraph', 'file-text', $this->element(FormFieldType::Paragraph, 'Add some text here.')),
                $this->item('divider', 'Divider', 'minus', $this->element(FormFieldType::Divider, '')),
                $this->item('spacer', 'Spacer', 'move-vertical', $this->element(FormFieldType::Spacer, '')),
            ]],
        ];

        $custom = [];
        foreach ($this->customFields->forBusiness($business) as $definition) {
            $custom[] = $this->item(
                'cf_'.$definition->uid,
                (string) $definition->label,
                'database',
                $this->customFieldElement($definition),
                ['custom_field_uid' => $definition->uid, 'token' => $definition->token()],
            );
        }
        $groups[] = ['id' => 'custom', 'label' => 'Custom fields', 'items' => $custom];

        $groups[] = ['id' => 'consent', 'label' => 'Consent', 'items' => [
            $this->item('consent_transactional', 'Transactional consent', 'shield', $this->element(FormFieldType::ConsentTransactional, self::TRANSACTIONAL_TEXT)),
            $this->item('consent_marketing', 'Marketing consent', 'shield', $this->element(FormFieldType::ConsentMarketing, self::MARKETING_TEXT)),
        ]];

        $groups[] = ['id' => 'submit', 'label' => 'Submit', 'items' => [
            ['id' => 'submit_button', 'label' => 'Submit button', 'icon' => 'send', 'special' => 'submit'],
        ]];

        return $groups;
    }

    /**
     * The element a Custom Field becomes when dropped on the canvas: the input
     * type that can hold what the field stores, the field's own label, and the
     * explicit `custom_field_uid` mapping.
     *
     * Email and Phone custom fields become plain short-text inputs ON PURPOSE: a
     * form's email and phone inputs are how the person is identified, and a
     * mapped custom field must never become that identity by accident.
     *
     * @return array<string, mixed>
     */
    public function customFieldElement($definition): array
    {
        $type = match ($definition->fieldType()) {
            CustomFieldType::LongText => FormFieldType::Textarea,
            CustomFieldType::Number => FormFieldType::Number,
            CustomFieldType::Currency => FormFieldType::Currency,
            CustomFieldType::Date => FormFieldType::Date,
            CustomFieldType::DateTime => FormFieldType::DateTime,
            CustomFieldType::Boolean => FormFieldType::Checkbox,
            CustomFieldType::Select => FormFieldType::Select,
            CustomFieldType::MultiSelect => FormFieldType::MultiSelect,
            default => FormFieldType::Text,
        };

        $element = $this->element($type, (string) $definition->label, ['custom_field_uid' => $definition->uid]);

        if ($type->hasOptions()) {
            $element['options'] = array_values(array_map(static fn (array $option): string => (string) $option['label'], $definition->optionList()));
        }

        return $element;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function element(FormFieldType $type, string $label, array $extra = []): array
    {
        return array_merge([
            'type' => $type->value,
            'label' => $label,
            'required' => false,
            'options' => [],
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function choice(FormFieldType $type, string $label): array
    {
        return $this->element($type, $label, ['options' => ['Option 1', 'Option 2']]);
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $id, string $label, string $icon, array $element, array $extra = []): array
    {
        return array_merge(['id' => $id, 'label' => $label, 'icon' => $icon, 'element' => $element], $extra);
    }
}
