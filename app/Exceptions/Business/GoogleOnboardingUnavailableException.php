<?php

namespace App\Exceptions\Business;

use RuntimeException;

/**
 * The Google quick start cannot be used for this onboarding right now. The
 * message is always safe to show a customer, and every caller pairs it with
 * the manual setup path — the quick start is an accelerator, never a gate.
 */
final class GoogleOnboardingUnavailableException extends RuntimeException
{
    public static function notEntitled(): self
    {
        return new self('Importing from Google is not included in your current plan. You can set up your business manually.');
    }

    public static function notAvailable(): self
    {
        return new self('Google import is not available right now. You can set up your business manually.');
    }
}
