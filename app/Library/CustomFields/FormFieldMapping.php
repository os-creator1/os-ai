<?php

namespace App\Library\CustomFields;

use App\Enums\CustomFields\CustomFieldType;
use App\Enums\Forms\FormFieldType;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Models\Business;
use App\Models\CustomFieldDefinition;

/**
 * The explicit "Save answer to" mapping between a Form / Questionnaire question
 * and a Business Custom Field.
 *
 * STABLE IDENTITY. A mapping is the custom field's `uid` stored on the question
 * inside the (immutable) form version — never a guess from the question label.
 * Renaming either side cannot change what is written where.
 *
 * WHAT IS ALLOWED TO BE MAPPED. Only a Contact custom field of THIS Business that
 * is not archived (a version already mapped to a field keeps that mapping when
 * the field is later archived; it is simply no longer writable, see
 * CustomFieldValueService::applyAnswer), and only to a type that can hold what
 * the question collects.
 */
class FormFieldMapping
{
    /** @var array<string, list<string>> form answer type => custom field types it may feed */
    private const COMPATIBLE = [
        'text' => ['text', 'long_text', 'number', 'currency', 'email', 'phone', 'select'],
        'textarea' => ['long_text', 'text'],
        'email' => ['email', 'text'],
        'phone' => ['phone', 'text'],
        'select' => ['select', 'multi_select', 'text'],
        'checkbox' => ['boolean'],
        'date' => ['date'],
        'number' => ['number', 'text'],
        'currency' => ['currency', 'number', 'text'],
        'datetime' => ['datetime', 'text'],
        'radio' => ['select', 'text'],
        'multi_select' => ['multi_select'],
        'yes_no' => ['text', 'select'],
    ];

    public function __construct(private readonly CustomFieldDefinitionManager $definitions)
    {
    }

    /** @return list<string> custom field type values a form answer type may feed */
    public static function compatibleTypes(string $formFieldType): array
    {
        return self::COMPATIBLE[$formFieldType] ?? [];
    }

    /**
     * Prove a mapping may be saved and return the uid to store.
     *
     * @param array{key: string, label: string, type: string, options: list<string>} $field the normalized question
     * @param bool $alreadyMapped the previous version already mapped this question to this uid
     *
     * @throws FormRuleException with a customer-facing message
     */
    public function assertMappable(Business $business, array $field, string $uid, bool $alreadyMapped): string
    {
        $definition = $this->definitions->findByUid($business, $uid);

        if ($definition === null) {
            // A foreign or unknown uid fails closed with the same words.
            throw new FormRuleException('"' . $field['label'] . '" is set to save to a contact field that does not exist.');
        }

        if ($definition->isArchived() && ! $alreadyMapped) {
            throw new FormRuleException('"' . $definition->label . '" is archived, so "' . $field['label'] . '" cannot be saved to it.');
        }

        if (! in_array($definition->type, self::compatibleTypes($field['type']), true)) {
            throw new FormRuleException(sprintf(
                '"%s" (%s) cannot be saved to "%s", which is a %s field.',
                $field['label'],
                FormFieldType::from($field['type'])->label(),
                $definition->label,
                CustomFieldType::from($definition->type)->label(),
            ));
        }

        if (FormFieldType::from($field['type'])->hasOptions() && $definition->fieldType()->hasOptions()) {
            $choices = array_map(static fn (array $option): string => mb_strtolower($option['label']), $definition->optionList());

            foreach ($field['options'] as $option) {
                if (! in_array(mb_strtolower($option), $choices, true)) {
                    throw new FormRuleException(sprintf('The option "%s" is not one of the choices of "%s".', $option, $definition->label));
                }
            }
        }

        return $definition->uid;
    }

    /**
     * The mapped answers of a submission, as `[definition, answer]` pairs ready
     * for CustomFieldValueService::applyAnswer().
     *
     * An unchecked checkbox is NOT an answer (it cannot be told apart from "did
     * not see it"), so it never overwrites an existing value; checked means Yes.
     *
     * @param list<array<string, mixed>> $fields the pinned version's fields
     * @param array<string, mixed> $values the submission's answers by field key
     *
     * @return list<array{0: CustomFieldDefinition, 1: mixed}>
     */
    public function answersFor(Business $business, array $fields, array $values): array
    {
        $mapped = array_values(array_filter($fields, fn (array $field): bool => ! empty($field['custom_field_uid'])));

        if ($mapped === []) {
            return [];
        }

        // One read for every mapped field, all proven against THIS Business.
        $definitions = $this->definitions->forBusiness($business, true)->keyBy('uid');
        $pairs = [];

        foreach ($mapped as $field) {
            $answer = $values[$field['key']] ?? null;

            if (($field['type'] ?? null) === FormFieldType::Checkbox->value && $answer !== true) {
                continue;
            }

            $definition = $definitions->get((string) $field['custom_field_uid']);

            if ($definition !== null) {
                $pairs[] = [$definition, $answer];
            }
        }

        return $pairs;
    }
}
