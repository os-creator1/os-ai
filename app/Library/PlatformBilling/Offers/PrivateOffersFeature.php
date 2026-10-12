<?php

namespace App\Library\PlatformBilling\Offers;

/**
 * The server-side switch for Private Offers (config/platform_billing.php).
 *
 * OFF unless the platform deliberately turns it on. It governs only NEW
 * commercial activity: creating or re-linking an offer, viewing or accepting
 * one, and applying a billing change. It never touches an agreement that is
 * already in force -- webhooks, renewals, schedule reconciliation, the
 * cancel/resume path, and revoking an offer all keep working, so a
 * subscriber's contractual billing terms are the same with the switch on or
 * off.
 */
final class PrivateOffersFeature
{
    public static function enabled(): bool
    {
        return (bool) config('platform_billing.private_offers_enabled', false);
    }
}
