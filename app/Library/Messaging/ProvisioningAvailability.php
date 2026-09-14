<?php

namespace App\Library\Messaging;

/**
 * Text messaging setup/number/compliance hub — the same two-gate check
 * TelnyxMessagingAdapter's/TelnyxProvisioningAdapter's own constructors
 * enforce (config('messaging.managed_messaging_enabled') plus every
 * required services.telnyx.* key), exposed as a cheap, side-effect-free
 * boolean a controller can check BEFORE deciding whether to show a live
 * "Get this number" affordance or a truthful inert preview state.
 *
 * Deliberately does not resolve MessagingProvisioningAdapter to find this
 * out — constructing the real adapter only to catch its own refusal would
 * make an ordinary page render depend on exception-driven control flow for
 * something that is, in truth, a plain config read. The adapter's own
 * constructor remains the actual, authoritative enforcement point for
 * every write path; this is a read-only convenience for presentation
 * only, and can never be more permissive than the adapter's own check.
 */
final class ProvisioningAvailability
{
    public static function isConfigured(): bool
    {
        if (! config('messaging.managed_messaging_enabled')) {
            return false;
        }

        // PR #295 Correction Round 1, item 5 — a separate, default-OFF
        // production gate for live number purchase/registration
        // specifically; normal managed SMS sending does not depend on it.
        if (! config('messaging.managed_messaging_provisioning_enabled')) {
            return false;
        }

        $apiKey = (string) (config('services.telnyx.api_key') ?? '');

        return $apiKey !== '';
    }
}
