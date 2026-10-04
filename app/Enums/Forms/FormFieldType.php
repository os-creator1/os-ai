<?php

namespace App\Enums\Forms;

/**
 * Forms — the CLOSED set of element types. Deliberately small: this is a lead
 * and questionnaire form, not a no-code application builder.
 *
 * Three kinds of element share the one ordered `fields` list of a version:
 *
 *  - INPUT types collect an answer (text … multi-select, yes/no).
 *  - CONSENT types are inputs that record an explicit, never pre-checked
 *    agreement. Transactional and marketing consent are separate types so they
 *    can never be bundled into one box.
 *  - CONTENT types (heading, paragraph, divider, spacer) only present
 *    information. They carry no answer, are never validated and never stored in a
 *    submission, and do not count toward the question limits.
 *
 * File upload, conditional logic, repeating groups and payment fields are
 * deliberately NOT here (see docs/automation/FORMS-VISUAL-BUILDER-V1.md).
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
    case Number = 'number';
    case Currency = 'currency';
    case DateTime = 'datetime';
    case MultiSelect = 'multi_select';
    case Radio = 'radio';
    case YesNo = 'yes_no';
    case ConsentTransactional = 'consent_transactional';
    case ConsentMarketing = 'consent_marketing';
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Divider = 'divider';
    case Spacer = 'spacer';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::Textarea => 'Long text',
            self::Email => 'Email address',
            self::Phone => 'Phone number',
            self::Select => 'Dropdown',
            self::Checkbox => 'Checkbox',
            self::Date => 'Date',
            self::Number => 'Number',
            self::Currency => 'Currency',
            self::DateTime => 'Date & time',
            self::MultiSelect => 'Multi-select',
            self::Radio => 'Radio buttons',
            self::YesNo => 'Yes / No',
            self::ConsentTransactional => 'Transactional consent',
            self::ConsentMarketing => 'Marketing consent',
            self::Heading => 'Heading',
            self::Paragraph => 'Paragraph',
            self::Divider => 'Divider',
            self::Spacer => 'Spacer',
        };
    }

    /** Presents information only: no answer, no validation, nothing stored. */
    public function isContent(): bool
    {
        return in_array($this, [self::Heading, self::Paragraph, self::Divider, self::Spacer], true);
    }

    /** Collects an answer (everything that is not content). */
    public function isInput(): bool
    {
        return ! $this->isContent();
    }

    public function isConsent(): bool
    {
        return $this === self::ConsentTransactional || $this === self::ConsentMarketing;
    }

    /** Needs an owner-authored option list. */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Radio], true);
    }

    /** Stored as a boolean (checked / not checked). */
    public function isBoolean(): bool
    {
        return $this === self::Checkbox || $this->isConsent();
    }

    /** Content types whose `label` is optional (a divider/spacer has no text). */
    public function allowsEmptyLabel(): bool
    {
        return $this === self::Divider || $this === self::Spacer;
    }
}
