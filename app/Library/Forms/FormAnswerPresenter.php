<?php

namespace App\Library\Forms;

use App\Enums\Forms\FormFieldType;
use App\Models\FormVersion;

/**
 * Presentation of a submission's answers, read ONLY against the FormVersion the
 * submission was answered against — never the form's current one — so a form edited
 * later (relabelled, reordered, a question removed) can never change how an old
 * response reads. Pure; reads nothing from persistence.
 */
final class FormAnswerPresenter
{
    /** A stored value as the owner reads it. */
    public static function display(array $field, mixed $value): string
    {
        $type = FormFieldType::tryFrom((string) ($field['type'] ?? '')) ?? FormFieldType::Text;

        if ($type->isBoolean()) {
            if ($type->isConsent()) {
                return $value ? 'Agreed' : 'Did not agree';
            }

            return $value ? 'Yes' : 'No';
        }

        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        return match (true) {
            is_array($value) => implode(', ', array_map('strval', $value)),
            $type === FormFieldType::DateTime => str_replace('T', ' ', (string) $value),
            default => (string) $value,
        };
    }

    /**
     * The first few answered questions, as `label => text`, for the response list.
     * Consent and checkbox answers are left to the detail view.
     *
     * @param  array<string, mixed>  $values
     * @return list<array{label: string, value: string}>
     */
    public static function keyAnswers(FormVersion $version, array $values, int $limit = 3): array
    {
        $out = [];

        foreach ($version->inputFields() as $field) {
            $type = FormFieldType::from($field['type']);

            if ($type->isBoolean()) {
                continue;
            }

            $text = self::display($field, $values[$field['key']] ?? null);

            if ($text === '') {
                continue;
            }

            $out[] = ['label' => (string) $field['label'], 'value' => mb_strimwidth($text, 0, 60, '…')];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The person's name from the version's EXPLICIT markers (full-name question, or
     * first + last), else an empty string. Never guessed from a label.
     *
     * @param  array<string, mixed>  $values
     */
    public static function personName(FormVersion $version, array $values): string
    {
        $fields = collect($version->fields ?? []);
        $full = $fields->firstWhere('contact_name', true)['key'] ?? null;

        if ($full !== null) {
            return trim((string) ($values[$full] ?? ''));
        }

        $first = $fields->firstWhere('contact_part', 'first_name')['key'] ?? null;
        $last = $fields->firstWhere('contact_part', 'last_name')['key'] ?? null;

        return trim(($first === null ? '' : (string) ($values[$first] ?? '')).' '.($last === null ? '' : (string) ($values[$last] ?? '')));
    }
}
