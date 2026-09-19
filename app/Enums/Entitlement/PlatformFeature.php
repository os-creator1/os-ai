<?php

namespace App\Enums\Entitlement;

/**
 * Stable, code-defined feature identity keys (RFC-004 §11). A case existing
 * here is not proof the feature is implemented — see
 * PlatformFeatureRegistry/PlatformFeatureAvailability for the separate,
 * equally code-backed availability concern, always checked before any plan
 * mapping or override is consulted.
 */
enum PlatformFeature: string
{
    case Crm = 'crm';
    case Conversations = 'conversations';
    case Calendar = 'calendar';
    case Forms = 'forms';
    case Automations = 'automations';
    case WebsiteGeneration = 'website_generation';
    case AiCooBasic = 'ai_coo_basic';
    case SeoBasicVisibility = 'seo_basic_visibility';
    case AdsBasicVisibility = 'ads_basic_visibility';
    case SeoModule = 'seo_module';
    case GoogleAdsModule = 'google_ads_module';
    case GoogleBusinessProfileModule = 'google_business_profile_module';
    case MetaAdsModule = 'meta_ads_module';
    case WhiteLabel = 'white_label';
    case AgencyPackageCapabilities = 'agency_package_capabilities';
    case ProspectOutreach = 'prospect_outreach';

    /**
     * Customer Experience Slice 3 §4.8 — additive, measurement-only.
     *
     * Messaging transport is measured (quantity and unit) without any retail
     * rate: Slice 3 activates no rate and takes no wallet reservation for
     * telecom transport.
     *
     * CORRECTED — Implementation Round 1. An earlier revision of this
     * docblock claimed Slice 3 "creates no
     * platform_feature_usage_classifications row for this case". That was
     * never achievable and is no longer what the contract asks for. The
     * merged migration
     * `2026_08_16_120008_backfill_platform_feature_usage_classifications`
     * inserts one row per PlatformFeature case and THROWS if any case lacks
     * one, so adding this case necessarily creates the row on any fresh
     * migrate, and merged migrations are not edited.
     *
     * The row therefore EXISTS, and is inactive, unmetered and unpriced —
     * `is_metered = 0`, `active_rate_id = NULL`, with zero rate and zero
     * activation rows. That is the invariant §4.8 and T-MSG-36 now state,
     * and it is strictly stronger than an absent row, which would prove
     * nothing about whether a rate was activated elsewhere. A later slice
     * that decides to price this feature is the one that changes those
     * fields.
     */
    case MessagingTransport = 'messaging_transport';

    /**
     * Implementation Contract 16 (Packages & Products catalog), Sub-slice A.
     * Starts `Planned` in PlatformFeatureRegistry — no controller, route, or
     * customer-facing surface exists yet (that is Sub-slice E's job, after
     * Sub-slices B/C/D land). Packaged for Core, Growth and Agency
     * (Blueprint §21) by this same sub-slice's plan-packaging migration,
     * independently of the availability flip, matching the
     * `GoogleBusinessProfileModule` precedent of packaging and availability
     * being seeded/flipped separately.
     */
    case PackagesProducts = 'packages_products';

    /**
     * Implementation Contract 17 (Payments & Contracts: Proposal / Contract /
     * e-signature / Invoice), Sub-slice A. Starts `Planned` in
     * PlatformFeatureRegistry — inert identity only: no controller, route,
     * view, provider call or customer-reachable surface exists yet. Packaged
     * for Core, Growth and Agency (Blueprint §21) by this sub-slice's
     * plan-packaging migration, independently of the availability flip, which
     * is Sub-slice G's last step once A-F are merged and verified end to end
     * (§6.4, §12.G). Money lane B only (Addendum §12) — never a lane A/C/D
     * feature.
     */
    case PaymentsContracts = 'payments_contracts';
}
