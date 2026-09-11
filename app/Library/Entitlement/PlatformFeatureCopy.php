<?php

namespace App\Library\Entitlement;

/**
 * What customers call a feature, and one sentence on what it does. Machine
 * keys (crm, website_generation, …) are identifiers, never customer copy.
 * Only features a customer can meet today have copy here; each sentence
 * describes what is implemented now, not what is planned.
 */
final class PlatformFeatureCopy
{
    private const COPY = [
        'crm' => ['Client Management', 'Manage your contacts and customer details.'],
        'conversations' => ['Inbox & Conversations', 'Read and reply to customer messages from one place.'],
        'automations' => ['Automations', 'Automatically follow up and perform repetitive tasks.'],
        'website_generation' => ['Website', 'Create and manage your business website.'],
        'google_business_profile_module' => ['Google Business Profile', 'Manage how your business appears on Google.'],
        'prospect_outreach' => ['Prospecting', 'Find potential clients for your agency and reach out to them.'],
    ];

    /**
     * Feature keys that have customer copy, in display order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::COPY);
    }

    public static function has(string $featureKey): bool
    {
        return isset(self::COPY[$featureKey]);
    }

    public static function name(string $featureKey): ?string
    {
        return self::COPY[$featureKey][0] ?? null;
    }

    public static function description(string $featureKey): ?string
    {
        return self::COPY[$featureKey][1] ?? null;
    }
}
