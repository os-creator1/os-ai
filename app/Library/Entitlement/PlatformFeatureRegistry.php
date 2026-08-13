<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\PlatformFeatureAvailability;

/**
 * Code-backed, static implementation-availability authority (RFC-004 §11),
 * checked strictly before any plan mapping or Workspace override is even
 * consulted. Distinct from PlatformFeature (identity) — a known feature key
 * is never, by itself, proof it may execute.
 *
 * AVAILABILITY below is locked to docs/automation/RFC-004-M1-CONTRACT.md §6's
 * evidence matrix, gathered by direct repository inspection at contract-
 * drafting time — Crm/Conversations/Automations confirmed via an existing
 * Contact/ChatBox/Automations controller each; every other case, including
 * ProspectOutreach, had no direct executable implementation found anywhere
 * in this repository. Only a future code deploy may change a value here.
 */
final class PlatformFeatureRegistry
{
    private const AVAILABILITY = [
        PlatformFeature::Crm->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Automations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Calendar->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::Forms->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::WebsiteGeneration->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::AiCooBasic->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::SeoBasicVisibility->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::AdsBasicVisibility->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::SeoModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::GoogleAdsModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::MetaAdsModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::WhiteLabel->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::AgencyPackageCapabilities->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::ProspectOutreach->value => PlatformFeatureAvailability::Planned,
    ];

    public static function isKnown(string $featureKey): bool
    {
        return PlatformFeature::tryFrom($featureKey) !== null;
    }

    public static function isAvailable(string $featureKey): bool
    {
        return self::isKnown($featureKey)
            && (self::AVAILABILITY[$featureKey] ?? null) === PlatformFeatureAvailability::Available;
    }
}
