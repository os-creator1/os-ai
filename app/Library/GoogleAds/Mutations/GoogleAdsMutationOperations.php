<?php

namespace App\Library\GoogleAds\Mutations;

use App\Enums\GoogleBusinessProfile\GoogleOperationType;

/**
 * Google Ads Module V1 contract §6 / §8 — the complete list of ledger
 * operation types that change Google Ads. View As is blocked at the route
 * layer for every mutation route; this is the single list that wiring (and a
 * test that no other `ads_*` write type exists) reads. The module has no
 * other write surface: no budget, bidding, targeting or creation type exists.
 */
final class GoogleAdsMutationOperations
{
    /** @return array<int, GoogleOperationType> */
    public static function types(): array
    {
        return [
            GoogleOperationType::AdsCampaignStatusChanged,
            GoogleOperationType::AdsKeywordStatusChanged,
            GoogleOperationType::AdsNegativeKeywordAdded,
        ];
    }

    /** @return array<int, string> the ledger `operation_type` values */
    public static function typeValues(): array
    {
        return array_map(static fn (GoogleOperationType $type): string => $type->value, self::types());
    }

    public static function isMutation(GoogleOperationType $type): bool
    {
        return in_array($type, self::types(), true);
    }
}
