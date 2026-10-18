<?php

namespace App\Enums\CustomFields;

/**
 * The closed set of Custom Field types (V1).
 *
 * `column()` names the typed storage column on `custom_field_values`;
 * `conditionFamily()` names the If/Else operator family. Both are decided by the
 * type alone so no reader ever has to guess how a value is stored or compared.
 * A field's type is chosen at creation and never changed afterwards: changing it
 * would reinterpret every stored value.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Currency = 'currency';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Email = 'email';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::LongText => 'Long text',
            self::Number => 'Number',
            self::Currency => 'Currency',
            self::Date => 'Date',
            self::DateTime => 'Date & time',
            self::Boolean => 'Yes / No',
            self::Select => 'Dropdown',
            self::MultiSelect => 'Multi-select',
            self::Email => 'Email',
            self::Phone => 'Phone',
        };
    }

    public function column(): string
    {
        return match ($this) {
            self::Text, self::LongText, self::Email, self::Phone, self::Select => 'value_text',
            self::Number, self::Currency => 'value_number',
            self::Date => 'value_date',
            self::DateTime => 'value_datetime',
            self::Boolean => 'value_bool',
            self::MultiSelect => 'value_json',
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    /** text | number | date | boolean | select | multi — the If/Else operator family. */
    public function conditionFamily(): string
    {
        return match ($this) {
            self::Number, self::Currency => 'number',
            self::Date, self::DateTime => 'date',
            self::Boolean => 'boolean',
            self::Select => 'select',
            self::MultiSelect => 'multi',
            default => 'text',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
