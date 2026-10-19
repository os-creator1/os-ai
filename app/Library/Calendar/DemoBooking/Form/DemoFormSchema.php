<?php

namespace App\Library\Calendar\DemoBooking\Form;

use Illuminate\Validation\Rule;

/**
 * Validates a Book a Demo form DEFINITION (what the Platform Owner saves) and turns a published definition into
 * validation rules and a frozen answer snapshot for a submission. Pure: no database, no request.
 */
final class DemoFormSchema
{
    public const TYPES = ['short_text', 'long_text', 'email', 'phone', 'dropdown', 'checkboxes', 'radio'];

    public const CHOICE_TYPES = ['dropdown', 'checkboxes', 'radio'];

    public const MAX_FIELDS = 25;

    public const MAX_OPTIONS = 30;

    /** Request inputs the booking itself uses; a form field can never take one of these names. */
    public const RESERVED = ['date', 'time', 'visitor_timezone', 'demo_hp', 'cf-turnstile-response', 'form_version', '_token', 'website', 'sms_consent'];

    public static function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');

        return substr($slug !== '' ? $slug : 'option', 0, 40);
    }

    /**
     * @param  array<int, mixed>  $input  the editor's field list
     * @return array{fields: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public static function normalize(array $input): array
    {
        $errors = [];
        $fields = [];
        $seen = [];
        $systemDefaults = [];
        foreach (DemoFormDefaults::fields() as $default) {
            $systemDefaults[$default['key']] = $default;
        }

        foreach (array_values($input) as $i => $raw) {
            $n = $i + 1;
            if (! is_array($raw)) {
                $errors[] = "Field {$n} is not valid.";
                continue;
            }
            $key = strtolower(trim((string) ($raw['key'] ?? '')));
            $label = trim((string) ($raw['label'] ?? ''));
            $system = array_key_exists($key, $systemDefaults) && in_array($key, DemoFormDefaults::SYSTEM, true);
            $type = $system ? $systemDefaults[$key]['type'] : (string) ($raw['type'] ?? '');

            if (! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) || in_array($key, self::RESERVED, true)) {
                $errors[] = "Field {$n} has an invalid identifier.";
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = "Field \"{$label}\" appears twice.";
                continue;
            }
            $seen[$key] = true;
            if (! in_array($type, self::TYPES, true)) {
                $errors[] = "Field \"{$label}\" has an unsupported type.";
                continue;
            }
            if ($label === '' || mb_strlen($label) > 120) {
                $errors[] = 'Every field needs a label of up to 120 characters.';
                continue;
            }

            $field = [
                'key' => $key, 'type' => $type, 'label' => $label,
                'placeholder' => mb_substr(trim((string) ($raw['placeholder'] ?? '')), 0, 120),
                'help' => mb_substr(trim((string) ($raw['help'] ?? '')), 0, 200),
                'required' => filter_var($raw['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'visible' => filter_var($raw['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'half' => filter_var($raw['half'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! in_array($type, ['long_text', 'checkboxes', 'radio'], true),
                'show_optional' => filter_var($raw['show_optional'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => [],
                'system' => $system,
            ];
            if (in_array($key, DemoFormDefaults::LOCKED, true)) {
                $field['required'] = true;
                $field['visible'] = true;
            }
            if (in_array($type, self::CHOICE_TYPES, true)) {
                $field['options'] = self::options($raw['options'] ?? [], $label, $errors);
            }
            $fields[] = $field;
        }

        foreach (DemoFormDefaults::SYSTEM as $required) {
            if (! isset($seen[$required])) {
                $errors[] = 'The "'.$systemDefaults[$required]['label'].'" field is needed for booking and cannot be removed.';
            }
        }
        if (count($fields) > self::MAX_FIELDS) {
            $errors[] = 'A form can have at most '.self::MAX_FIELDS.' fields.';
        }

        return ['fields' => $fields, 'errors' => array_values(array_unique($errors))];
    }

    /** @return array<int, array{value: string, label: string}> */
    private static function options(mixed $raw, string $fieldLabel, array &$errors): array
    {
        $options = [];
        $values = [];
        foreach ((is_array($raw) ? $raw : []) as $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? '') : $option));
            if ($label === '') {
                continue;
            }
            $value = is_array($option) ? strtolower(trim((string) ($option['value'] ?? ''))) : '';
            if (! preg_match('/^[a-z0-9][a-z0-9_]{0,39}$/', $value)) {
                $value = self::slug($label);
            }
            for ($base = $value, $n = 2; isset($values[$value]); $n++) {
                $value = substr($base, 0, 36).'_'.$n;
            }
            $values[$value] = true;
            $options[] = ['value' => $value, 'label' => mb_substr($label, 0, 80)];
        }
        if ($options === []) {
            $errors[] = "\"{$fieldLabel}\" needs at least one option.";
        }
        if (count($options) > self::MAX_OPTIONS) {
            $errors[] = "\"{$fieldLabel}\" can have at most ".self::MAX_OPTIONS.' options.';
        }

        return $options;
    }

    /** @return array<int, array<string, mixed>> */
    public static function visible(array $fields): array
    {
        return array_values(array_filter($fields, static fn (array $f): bool => $f['visible'] ?? true));
    }

    /**
     * Laravel rules for the visible fields. The phone format check is a rule of its own so it reports on the field.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $fields): array
    {
        $rules = [];
        foreach (self::visible($fields) as $f) {
            $key = $f['key'];
            $presence = $f['required'] ? 'required' : 'nullable';
            $values = array_column($f['options'] ?? [], 'value');
            switch ($f['type']) {
                case 'long_text':
                    $rules[$key] = [$presence, 'string', 'max:2000'];
                    break;
                case 'email':
                    $rules[$key] = [$presence, 'string', 'email:rfc', 'max:190'];
                    break;
                case 'phone':
                    $rules[$key] = [$presence, 'string', 'max:32', static function (string $attribute, mixed $value, \Closure $fail): void {
                        $phone = trim((string) $value);
                        $digits = trim(str_replace(['+', '-', '(', ')', ' '], '', $phone));
                        if ($phone !== '' && ($digits === '' || ! ctype_digit($digits))) {
                            $fail('Enter a valid phone number.');
                        }
                    }];
                    break;
                case 'dropdown':
                case 'radio':
                    $rules[$key] = [$presence, 'string', Rule::in($values)];
                    break;
                case 'checkboxes':
                    $rules[$key] = [$presence, 'array', ...($f['required'] ? ['min:1'] : [])];
                    $rules[$key.'.*'] = ['string', Rule::in($values)];
                    break;
                default:
                    $rules[$key] = [$presence, 'string', 'max:'.(in_array($key, ['first_name', 'last_name'], true) ? 120 : 190)];
            }
        }

        return $rules;
    }

    /**
     * The frozen answer rows for one submission: label and value exactly as the visitor saw them.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array{key: string, type: string, label: string, value: string|array<int, string>, display: string}>
     */
    public static function snapshot(array $fields, array $validated): array
    {
        $rows = [];
        foreach (self::visible($fields) as $f) {
            $value = $validated[$f['key']] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $labels = array_column($f['options'] ?? [], 'label', 'value');
            $display = is_array($value)
                ? implode(', ', array_map(static fn ($v) => $labels[$v] ?? $v, $value))
                : (string) ($labels[$value] ?? $value);
            $rows[] = ['key' => $f['key'], 'type' => $f['type'], 'label' => $f['label'], 'value' => $value, 'display' => $display];
        }

        return $rows;
    }
}
