<?php

namespace App\Library\CustomFields;

use App\Enums\CustomFields\CustomFieldType;
use App\Models\Business;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Carbon\Carbon;

/**
 * The single place that knows how a Custom Field value is validated, stored,
 * read back and shown.
 *
 * Canonical in-memory forms:
 *   text/long_text/email/phone  string
 *   number/currency             string, plain decimal ("180", "1500.5")
 *   date                        "Y-m-d"
 *   datetime                    "Y-m-d H:i:s" (Business wall-clock, no zone conversion)
 *   boolean                     bool
 *   select                      option id
 *   multi_select                list of option ids
 *
 * Validation is strict and throws CustomFieldRuleException with a message fit for
 * a customer. "Blank" (null, "", whitespace, empty list) is never a valid value —
 * callers decide whether blank means "clear" or "ignore".
 */
final class CustomFieldValueCodec
{
    public const MAX_TEXT = 255;

    public const MAX_LONG_TEXT = 5000;

    public static function isBlank(mixed $raw): bool
    {
        if ($raw === null) {
            return true;
        }

        if (is_array($raw)) {
            return array_filter($raw, static fn ($item): bool => ! self::isBlank($item)) === [];
        }

        if (is_bool($raw)) {
            return false;
        }

        return trim((string) $raw) === '';
    }

    /**
     * @throws CustomFieldRuleException
     */
    public static function normalize(CustomFieldDefinition $definition, mixed $raw): mixed
    {
        $label = (string) $definition->label;

        if (self::isBlank($raw)) {
            throw new CustomFieldRuleException(sprintf('%s needs a value.', $label));
        }

        return match ($definition->fieldType()) {
            CustomFieldType::Text => self::text($label, $raw, self::MAX_TEXT),
            CustomFieldType::LongText => self::text($label, $raw, self::MAX_LONG_TEXT),
            CustomFieldType::Email => self::email($label, $raw),
            CustomFieldType::Phone => self::phone($label, $raw),
            CustomFieldType::Number, CustomFieldType::Currency => self::number($label, $raw),
            CustomFieldType::Date => self::date($label, $raw),
            CustomFieldType::DateTime => self::dateTime($label, $raw),
            CustomFieldType::Boolean => self::boolean($label, $raw),
            CustomFieldType::Select => self::select($definition, $raw),
            CustomFieldType::MultiSelect => self::multiSelect($definition, $raw),
        };
    }

    /**
     * Every typed column, with only the type's own column set.
     *
     * @return array<string, mixed>
     */
    public static function toColumns(CustomFieldType $type, mixed $canonical): array
    {
        $columns = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_datetime' => null,
            'value_bool' => null,
            'value_json' => null,
        ];

        // value_json carries an array cast, so the model encodes it exactly once.
        $columns[$type->column()] = $type === CustomFieldType::MultiSelect ? array_values($canonical) : $canonical;

        return $columns;
    }

    /** The canonical value of a stored row, or null when its column is empty. */
    public static function fromRow(CustomFieldType $type, CustomFieldValue $row): mixed
    {
        $value = $row->getAttribute($type->column());

        if ($value === null) {
            return null;
        }

        return match ($type) {
            CustomFieldType::Number, CustomFieldType::Currency => self::trimDecimal((string) $value),
            CustomFieldType::Date => Carbon::parse((string) $value)->format('Y-m-d'),
            CustomFieldType::DateTime => Carbon::parse((string) $value)->format('Y-m-d H:i:s'),
            CustomFieldType::Boolean => (bool) $value,
            CustomFieldType::MultiSelect => is_array($value) ? array_values($value) : [],
            default => (string) $value,
        };
    }

    /** The human-readable text a merge token or a read-only view shows. */
    public static function display(CustomFieldDefinition $definition, mixed $canonical, ?Business $business = null): string
    {
        if ($canonical === null) {
            return '';
        }

        return match ($definition->fieldType()) {
            CustomFieldType::Date => Carbon::parse((string) $canonical)->format('j M Y'),
            CustomFieldType::DateTime => Carbon::parse((string) $canonical)->format('j M Y, g:i A'),
            CustomFieldType::Boolean => $canonical ? 'Yes' : 'No',
            CustomFieldType::Currency => self::money((string) $canonical, $business?->currency_code),
            CustomFieldType::Select => self::optionLabel($definition, (string) $canonical),
            CustomFieldType::MultiSelect => implode(', ', array_filter(array_map(
                fn ($id): string => self::optionLabel($definition, (string) $id),
                (array) $canonical,
            ), static fn (string $label): bool => $label !== '')),
            default => (string) $canonical,
        };
    }

    /**
     * A stored canonical value shaped for an HTML control: `datetime-local`
     * wants `Y-m-d\TH:i`, a Yes/No select wants `yes` / `no` / ``, a multi-select
     * wants the list of option ids. Null stays null (an unset field).
     */
    public static function toInput(CustomFieldDefinition $definition, mixed $canonical): mixed
    {
        if ($canonical === null) {
            return $definition->fieldType() === CustomFieldType::MultiSelect ? [] : '';
        }

        return match ($definition->fieldType()) {
            CustomFieldType::DateTime => Carbon::parse((string) $canonical)->format('Y-m-d\TH:i'),
            CustomFieldType::Boolean => $canonical ? 'yes' : 'no',
            default => $canonical,
        };
    }

    private static function text(string $label, mixed $raw, int $max): string
    {
        if (is_array($raw) || is_object($raw)) {
            throw new CustomFieldRuleException(sprintf('%s must be text.', $label));
        }

        $value = trim((string) $raw);

        if (mb_strlen($value) > $max) {
            throw new CustomFieldRuleException(sprintf('%s must be %d characters or fewer.', $label, $max));
        }

        return $value;
    }

    private static function email(string $label, mixed $raw): string
    {
        $value = self::text($label, $raw, self::MAX_TEXT);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new CustomFieldRuleException(sprintf('%s must be a valid email address.', $label));
        }

        return $value;
    }

    private static function phone(string $label, mixed $raw): string
    {
        $value = self::text($label, $raw, 32);
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (preg_match('/^\+?[0-9\s().-]+$/', $value) !== 1 || strlen($digits) < 5 || strlen($digits) > 15) {
            throw new CustomFieldRuleException(sprintf('%s must be a valid phone number.', $label));
        }

        return $value;
    }

    private static function number(string $label, mixed $raw): string
    {
        if (is_array($raw) || is_bool($raw)) {
            throw new CustomFieldRuleException(sprintf('%s must be a number.', $label));
        }

        $value = str_replace(',', '', trim((string) $raw));

        if (preg_match('/^-?\d{1,16}(\.\d{1,4})?$/', $value) !== 1) {
            throw new CustomFieldRuleException(sprintf('%s must be a number.', $label));
        }

        return self::trimDecimal($value);
    }

    private static function date(string $label, mixed $raw): string
    {
        $value = trim((string) $raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new CustomFieldRuleException(sprintf('%s must be a date.', $label));
        }

        $parsed = Carbon::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new CustomFieldRuleException(sprintf('%s must be a real date.', $label));
        }

        return $value;
    }

    private static function dateTime(string $label, mixed $raw): string
    {
        $value = str_replace('T', ' ', trim((string) $raw));

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
            throw new CustomFieldRuleException(sprintf('%s must be a date and time.', $label));
        }

        if (strlen($value) === 16) {
            $value .= ':00';
        }

        $parsed = Carbon::createFromFormat('!Y-m-d H:i:s', $value);

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new CustomFieldRuleException(sprintf('%s must be a real date and time.', $label));
        }

        return $value;
    }

    private static function boolean(string $label, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return match (mb_strtolower(trim((string) $raw))) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => throw new CustomFieldRuleException(sprintf('%s must be Yes or No.', $label)),
        };
    }

    private static function select(CustomFieldDefinition $definition, mixed $raw): string
    {
        if (is_array($raw)) {
            throw new CustomFieldRuleException(sprintf('%s accepts one choice.', $definition->label));
        }

        return self::optionId($definition, trim((string) $raw));
    }

    /** @return list<string> */
    private static function multiSelect(CustomFieldDefinition $definition, mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($items as $item) {
            if (self::isBlank($item)) {
                continue;
            }

            $ids[] = self::optionId($definition, trim((string) $item));
        }

        return array_values(array_unique($ids));
    }

    /** An option id, matched by stable id first and then by label (Form answers carry labels). */
    private static function optionId(CustomFieldDefinition $definition, string $needle): string
    {
        foreach ($definition->optionList() as $option) {
            if ($option['id'] === $needle) {
                return $option['id'];
            }
        }

        foreach ($definition->optionList() as $option) {
            if (mb_strtolower($option['label']) === mb_strtolower($needle)) {
                return $option['id'];
            }
        }

        throw new CustomFieldRuleException(sprintf('%s: that is not one of the choices.', $definition->label));
    }

    private static function optionLabel(CustomFieldDefinition $definition, string $id): string
    {
        foreach ($definition->optionList() as $option) {
            if ($option['id'] === $id) {
                return $option['label'];
            }
        }

        // An option removed after the value was stored resolves to nothing,
        // never to the raw internal id.
        return '';
    }

    private static function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' || $value === '-' ? '0' : $value;
    }

    public static function money(string $amount, ?string $currency): string
    {
        $formatted = number_format((float) $amount, str_contains($amount, '.') ? 2 : 0, '.', ',');
        $currency = $currency !== null && $currency !== '' ? strtoupper($currency) : '';

        return trim($currency . ' ' . $formatted);
    }
}
