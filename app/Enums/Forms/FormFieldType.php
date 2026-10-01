<?php

namespace App\Enums\Forms;

/**
 * Forms V1 — the CLOSED set of field types. Deliberately small: this is a lead
 * and questionnaire form, not a no-code application builder. A type earns a
 * place here only when a local-service business genuinely needs it to take an
 * inquiry (name, phone, email, a date, a free-text message, a pick-one, a
 * yes/no consent). File upload, conditional logic, repeating groups and
 * payment fields are deliberately NOT here.
 *
 * `Phone` is special: it is the identity key for Contact resolution (the
 * Contacts domain is phone-keyed), so a form may carry at most one.
 */
enum FormFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Date = 'date';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Textarea => 'Long text',
            self::Email => 'Email address',
            self::Phone => 'Phone number',
            self::Select => 'Pick one',
            self::Checkbox => 'Yes / no',
            self::Date => 'Date',
        };
    }
}
