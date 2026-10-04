<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §6 — the three mutation kinds and the Ads-domain target each one
 * addresses (google_ads_mutations.kind / target_type).
 */
enum GoogleAdsMutationKind: string
{
    case CampaignStatus = 'campaign_status';
    case KeywordStatus = 'keyword_status';
    case NegativeKeyword = 'negative_keyword';

    /** The ledger type that carries this kind's durable operation row. */
    public function operationType(): \App\Enums\GoogleBusinessProfile\GoogleOperationType
    {
        return match ($this) {
            self::CampaignStatus => \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsCampaignStatusChanged,
            self::KeywordStatus => \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsKeywordStatusChanged,
            self::NegativeKeyword => \App\Enums\GoogleBusinessProfile\GoogleOperationType::AdsNegativeKeywordAdded,
        };
    }
}
