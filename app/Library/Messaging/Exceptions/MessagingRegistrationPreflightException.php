<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Our own pre-send check refused a registration before any provider request
 * was made, so nothing reached the carrier and nothing was charged. It carries
 * only the names of the saved details that failed (never their values).
 */
class MessagingRegistrationPreflightException extends RuntimeException
{
    /** @param list<string> $fields keys of PREFLIGHT_LABELS */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Registration preflight failed: ' . implode(', ', $fields));
    }

    /** Field key => customer-facing label. */
    public const LABELS = [
        'use_case' => 'messaging type',
        'message_volume' => 'expected messages per month',
        'state' => 'state',
        'contact_name' => 'contact first and last name',
        'opt_in_url' => 'opt-in link',
    ];

    /** @return list<string> */
    public function labels(): array
    {
        return array_values(array_map(fn (string $f): string => self::LABELS[$f] ?? $f, $this->fields));
    }
}
