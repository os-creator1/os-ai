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
 * Contact/ChatBox/Automations controller each; every other case had no
 * direct executable implementation found anywhere in this repository at
 * that time. Only a future code deploy may change a value here.
 *
 * ProspectOutreach flipped to Available by the Agency AI Prospecting
 * foundation pass: a real, executable, Workspace-scoped controller/routes/
 * persistence now exists (App\Http\Controllers\Customer\Workspace\
 * AgencyProspectingController and its agency_prospect_* schema), matching
 * the exact evidentiary bar Crm/Conversations/Automations were already
 * held to. This flip does not, by itself, claim the automatic AI responder
 * engine or Workspace-level provider sending are implemented — both remain
 * explicitly deferred (see the foundation pass's own report); it only
 * reflects that the feature now has a real, reachable, Agency-gated
 * surface, exactly as this class's own contract requires.
 */
final class PlatformFeatureRegistry
{
    private const AVAILABILITY = [
        PlatformFeature::Crm->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Automations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::ProspectOutreach->value => PlatformFeatureAvailability::Available,
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
