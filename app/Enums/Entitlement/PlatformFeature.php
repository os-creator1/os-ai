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
    case MetaAdsModule = 'meta_ads_module';
    case WhiteLabel = 'white_label';
    case AgencyPackageCapabilities = 'agency_package_capabilities';
    case ProspectOutreach = 'prospect_outreach';
}
