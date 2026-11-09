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
    /** A closed, secret-free code for operator diagnostics (never shown to customers). */
    public string $reasonCode = 'unavailable';

    public static function notEntitled(): self
    {
        $e = new self('Importing from Google is not included in your current plan. You can set up your business manually.');
        $e->reasonCode = 'entitlement_denied';

        return $e;
    }

    public static function notAvailable(): self
    {
        $e = new self('Google import is not available right now. You can set up your business manually.');
        $e->reasonCode = 'workspace_unavailable';

        return $e;
    }

    public static function nothingToLink(): self
    {
        return new self('There is no Google profile waiting to be linked. Choose your profile again.');
    }

    public static function reconnectToLink(): self
    {
        return new self('Your Google connection is no longer active. Connect Google again to link your profile.');
    }

    public static function locationFirst(): self
    {
        return new self('Finish saving where you work first, then link your Google profile.');
    }

    public static function linkedElsewhere(): self
    {
        return new self('This location is already linked to a different Google profile. You can change that from the Google section after setup.');
    }
}
