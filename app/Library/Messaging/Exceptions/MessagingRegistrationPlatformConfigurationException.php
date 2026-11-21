<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * A platform-level (not customer) setting required by the carrier is missing,
 * so a submission was refused before any provider request: nothing was sent
 * and nothing was charged. Carries only the config key names, never values.
 */
class MessagingRegistrationPlatformConfigurationException extends RuntimeException
{
    /** @param list<string> $settings environment/config setting names */
    public function __construct(public readonly array $settings)
    {
        parent::__construct('Messaging platform configuration is incomplete: ' . implode(', ', $settings));
    }
}
