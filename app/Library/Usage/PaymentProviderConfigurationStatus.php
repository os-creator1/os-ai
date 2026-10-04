<?php

namespace App\Library\Usage;

/**
 * Canonical read-only readiness seam for the Stripe payment provider
 * configuration. Reading this never constructs a gateway, never makes a
 * provider call and never exposes a secret value. The same rules back
 * StripePaymentProviderGateway's fail-closed check at the actual
 * money-moving/provider-action boundary.
 */
final class PaymentProviderConfigurationStatus
{
    /**
     * @return string|null why the provider is not usable, or null when configured
     */
    public static function problem(): ?string
    {
        $mode = config('services.stripe.mode');
        $secret = (string) config('services.stripe.secret');

        if (! in_array($mode, ['test', 'live'], true)) {
            return 'services.stripe.mode must be "test" or "live".';
        }

        if ($secret === '') {
            return 'services.stripe.secret must not be empty.';
        }

        if (blank(config('services.stripe.webhook.secret'))) {
            return 'services.stripe.webhook.secret must not be empty.';
        }

        if (blank(config('services.stripe.api_version'))) {
            return 'services.stripe.api_version must not be empty.';
        }

        if (! str_starts_with($secret, 'sk_'.$mode.'_')) {
            return 'services.stripe.secret does not match the configured services.stripe.mode.';
        }

        return null;
    }

    public static function isConfigured(): bool
    {
        return self::problem() === null;
    }

    /** Operator-facing label; never includes configuration values. */
    public static function label(): string
    {
        return self::isConfigured() ? 'Configured' : 'Not configured';
    }
}
