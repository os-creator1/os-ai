<?php

namespace App\Library\PlatformOwner;

use App\Library\Usage\PaymentProviderConfigurationStatus;

/**
 * Platform Owner V1 final — one honest answer per external provider, from
 * configuration alone: no network call, no client construction, no secret
 * value (only whether something is set). Three states:
 *
 *   not_configured  nothing set — expected on a fresh install
 *   connected       everything the provider needs is set and self-consistent
 *   attention       partly set, or set inconsistently; `detail` names what to fix
 *
 * "Connected" means configured and consistent, not "we just reached the
 * provider": reachability is the provider-events and webhook-health screens'
 * job, and a read-only page must never depend on a provider being up.
 */
class PlatformProviderReadiness
{
    public const NOT_CONFIGURED = 'not_configured';

    public const CONNECTED = 'connected';

    public const ATTENTION = 'attention';

    /** @return list<array{key: string, name: string, purpose: string, state: string, label: string, detail: ?string}> */
    public function all(): array
    {
        return [
            $this->stripe(),
            $this->fromKeys('messaging', 'Messaging (Telnyx)', 'Business phone numbers, SMS and calls', [
                'API key' => config('services.telnyx.api_key'),
                'Webhook public key' => config('services.telnyx.webhook_public_key'),
            ]),
            $this->fromKeys('ai', 'AI (OpenAI)', 'AI COO and content generation', [
                'API key' => config('services.openai.api_key'),
            ]),
            $this->fromKeys('google', 'Google (Business Profile & sign-in)', 'Google Business Profile, Search Console and sign-in', [
                'Business Profile client ID' => config('services.google_business_profile.client_id'),
                'Business Profile client secret' => config('services.google_business_profile.client_secret'),
            ]),
            $this->fromKeys('google_ads', 'Google Ads', 'Google Ads module', [
                'Client ID' => config('services.google_ads.client_id'),
                'Client secret' => config('services.google_ads.client_secret'),
            ]),
            $this->fromKeys('seo_data', 'SEO data (DataForSEO)', 'Keyword rank tracking', [
                'Login' => config('seo.rank_tracking.dataforseo.login'),
                'Password' => config('seo.rank_tracking.dataforseo.password'),
            ]),
        ];
    }

    /** @return array{key: string, name: string, purpose: string, state: string, label: string, detail: ?string} */
    private function stripe(): array
    {
        $anySet = filled(config('services.stripe.secret')) || filled(config('services.stripe.webhook.secret'))
            || filled(config('services.stripe.platform_subscription_webhook.secret'));

        if (! $anySet) {
            return $this->row('stripe', 'Payments (Stripe)', 'Platform subscriptions and customer payments', self::NOT_CONFIGURED, null);
        }

        $problem = PaymentProviderConfigurationStatus::problem();

        if ($problem === null && blank(config('services.stripe.platform_subscription_webhook.secret'))) {
            $problem = 'The platform-subscription webhook signing secret is not set.';
        }

        return $this->row('stripe', 'Payments (Stripe)', 'Platform subscriptions and customer payments', $problem === null ? self::CONNECTED : self::ATTENTION, $problem);
    }

    /** @param array<string, mixed> $required */
    private function fromKeys(string $key, string $name, string $purpose, array $required): array
    {
        $missing = array_keys(array_filter($required, fn ($v) => blank($v)));

        if (count($missing) === count($required)) {
            return $this->row($key, $name, $purpose, self::NOT_CONFIGURED, null);
        }

        return $this->row($key, $name, $purpose, $missing === [] ? self::CONNECTED : self::ATTENTION, $missing === [] ? null : 'Missing: ' . implode(', ', $missing) . '.');
    }

    private function row(string $key, string $name, string $purpose, string $state, ?string $detail): array
    {
        return [
            'key' => $key, 'name' => $name, 'purpose' => $purpose, 'state' => $state, 'detail' => $detail,
            'label' => ['not_configured' => 'Not configured', 'connected' => 'Connected', 'attention' => 'Needs attention'][$state],
        ];
    }
}
