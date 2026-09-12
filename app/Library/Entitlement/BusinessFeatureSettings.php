<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;

/**
 * The Business feature switches a customer sees on the account page, built
 * from decisions EntitlementManager has already made — this class holds no
 * entitlement policy of its own, it only chooses what to show.
 *
 * A feature is shown only when all four hold:
 *  1. it is Business-scoped and 2. Available — decideAvailableFeaturesForBusiness()
 *     returns nothing else;
 *  3. the Business is entitled to it: the manager allows it, or the only thing
 *     stopping it is this Business's own switch (so it can be turned back on);
 *     anything the plan does not include is left out, never shown as locked;
 *  4. it is customer-toggleable: its switch is honoured where the feature
 *     runs, so turning it off really turns it off (CUSTOMER_TOGGLEABLE).
 */
final class BusinessFeatureSettings
{
    /**
     * Features whose Business switch the running product consults today:
     * Inbox (conversation access, realtime channel, sending), Automations
     * (pages and the automation runtime), Website (editor and the public
     * site) and Google Business Profile (pages and the profile sync) all ask
     * EntitlementManager::decide(), and the menu hides them. `crm` is
     * Available and Business-scoped, but nothing reads its switch yet
     * (Contacts does not check it and the menu deliberately does not gate
     * Contacts), so a switch for it would change nothing; it belongs here
     * once Contacts honours it. Listed in display order.
     */
    public const CUSTOMER_TOGGLEABLE = [
        PlatformFeature::Conversations->value,
        PlatformFeature::Automations->value,
        PlatformFeature::WebsiteGeneration->value,
        PlatformFeature::GoogleBusinessProfileModule->value,
    ];

    private const DISABLED_FOR_BUSINESS = 'disabled_for_business';

    /**
     * @param  array<string, array{decision: EntitlementDecision, disablePreferenceRecorded: bool}>  $decisions
     *         EntitlementManager::decideAvailableFeaturesForBusiness() for one Business
     * @return list<array{key: string, name: string, description: string, enabled: bool}>
     */
    public static function fromDecisions(array $decisions): array
    {
        $settings = [];

        foreach (self::CUSTOMER_TOGGLEABLE as $featureKey) {
            $row = $decisions[$featureKey] ?? null;

            if ($row === null || ! PlatformFeatureCopy::has($featureKey)) {
                continue;
            }

            $decision = $row['decision'];
            $entitled = $decision->allowed || $decision->reason === self::DISABLED_FOR_BUSINESS;

            if (! $entitled) {
                continue;
            }

            $settings[] = [
                'key' => $featureKey,
                'name' => PlatformFeatureCopy::name($featureKey),
                'description' => PlatformFeatureCopy::description($featureKey),
                'enabled' => ! $row['disablePreferenceRecorded'],
            ];
        }

        return $settings;
    }
}
