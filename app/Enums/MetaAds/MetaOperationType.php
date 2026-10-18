<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §3 — business_meta_operations.operation_type (varchar(40)).
 * The values are persisted; changing one is a data migration.
 */
enum MetaOperationType: string
{
    case ConnectInitiated = 'connect_initiated';
    case ConnectCompleted = 'connect_completed';
    case ConnectFailed = 'connect_failed';
    case Disconnected = 'disconnected';
    case TokenExpired = 'token_expired';
    case AccountsListed = 'accounts_listed';
    case AccountSelected = 'account_selected';
    case MetaAdsSync = 'meta_ads_sync';
    case CampaignStatusChanged = 'campaign_status_changed';
    case AdSetStatusChanged = 'ad_set_status_changed';
    case AdStatusChanged = 'ad_status_changed';
}
