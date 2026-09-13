<?php

namespace App\Enums\Crm;

/**
 * Whether the Business has been in touch with the person behind a deal yet.
 *
 * Deliberately NOT pipeline stages: a deal in "New inquiry" can be either, and
 * that distinction is exactly what a new-inquiry follow-up needs to know.
 */
enum CrmContactStatus: string
{
    case NoContact = 'no_contact';
    case InContact = 'in_contact';

    public function label(): string
    {
        return match ($this) {
            self::NoContact => 'No contact',
            self::InContact => 'In contact',
        };
    }
}
