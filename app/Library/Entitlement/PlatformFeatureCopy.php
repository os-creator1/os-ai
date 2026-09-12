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
        'prospect_outreach' => ['Prospecting', 'Keep a list of prospective clients and reach them with outreach campaigns.'],
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

    /**
     * Customer names for a list of feature keys, in this class's order. Keys
     * without copy — features not built yet, or internal ones — are left out,
     * so a plan never lists something a customer cannot use.
     *
     * @param  iterable<string>  $featureKeys
     * @return list<string>
     */
    public static function names(iterable $featureKeys): array
    {
        $wanted = [];

        foreach ($featureKeys as $featureKey) {
            $wanted[(string) $featureKey] = true;
        }

        return array_values(array_map(
            fn (array $copy): string => $copy[0],
            array_intersect_key(self::COPY, $wanted),
        ));
    }
}
