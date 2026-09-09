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
     * rate: Slice 3 activates no rate, creates no
     * platform_feature_usage_classifications row for this case, and takes no
     * wallet reservation for telecom transport. A later slice that decides
     * to price it is the one that adds those rows.
     */
    case MessagingTransport = 'messaging_transport';
}
