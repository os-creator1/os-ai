<?php

namespace App\Library\PlatformOwner;

use App\Enums\Entitlement\PlatformFeature;

/**
 * Human grouping of PlatformFeature keys for the plan packaging UI. Keys are
 * identifiers and never shown as the primary label. A case missing here is
 * still shown, under "Other", so a new feature can never silently disappear
 * from packaging.
 */
final class PlatformFeatureGroups
{
    public const OTHER = 'Other';

    /** @var array<string, array<string, string>> group => feature key => label */
    private const GROUPS = [
        'CRM' => ['crm' => 'Contacts & pipeline'],
        'Conversations' => ['conversations' => 'Inbox & conversations', 'messaging_transport' => 'Messaging transport'],
        'Calendar' => ['calendar' => 'Calendar & booking'],
        'Automations' => ['automations' => 'Automations'],
        'Website' => ['website_generation' => 'Website builder'],
        'SEO' => [
            'seo_basic_visibility' => 'SEO basics', 'seo_module' => 'SEO module',
            'seo_rank_tracking' => 'Keyword rank tracking', 'google_business_profile_module' => 'Google Business Profile',
        ],
        'Ads' => [
            'ads_basic_visibility' => 'Ads basics', 'google_ads_module' => 'Google Ads', 'meta_ads_module' => 'Meta Ads',
        ],
        'Forms' => ['forms' => 'Forms & lead capture'],
        'Packages' => ['packages_products' => 'Packages & products catalog'],
        'Payments & Contracts' => ['payments_contracts' => 'Proposals, contracts, e-sign & invoices'],
        'AI' => ['ai_coo_basic' => 'AI COO'],
        'Agency' => [
            'white_label' => 'White label', 'agency_package_capabilities' => 'Agency package capabilities',
            'prospect_outreach' => 'Prospecting & outreach',
        ],
    ];

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        $groups = self::GROUPS;
        $known = array_merge(...array_values(self::GROUPS));

        foreach (PlatformFeature::cases() as $case) {
            if (! isset($known[$case->value])) {
                $groups[self::OTHER][$case->value] = ucfirst(str_replace('_', ' ', $case->value));
            }
        }

        return $groups;
    }

    /** @return list<string> */
    public static function allKeys(): array
    {
        return array_map(fn (PlatformFeature $f) => $f->value, PlatformFeature::cases());
    }
}
