<?php

namespace App\Library\Messaging\Exceptions;

use RuntimeException;

/**
 * Slice 3 §4.3/§4.4 — thrown before any HTTP call when required
 * configuration is absent or incomplete, or when the platform-wide
 * managed_messaging_enabled kill switch is off.
 *
 * Names the missing config KEY only, never a value (T-MSG-41).
 */
class MessagingProviderNotConfiguredException extends RuntimeException
{
    public static function missingKey(string $configKey): self
    {
        return new self(sprintf(
            'Managed messaging is not configured: [%s] is empty or absent.',
            $configKey,
        ));
    }

    public static function disabled(): self
    {
        return new self(
            'Managed messaging is disabled: [messaging.managed_messaging_enabled] is false.',
        );
    }
}
