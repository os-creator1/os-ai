<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §12 — the owner's per-search-term classification. It is NOT a
 * recommendation lifecycle (that belongs to the Opportunity Engine, D7).
 */
enum GoogleAdsSearchTermReviewState: string
{
    case Unreviewed = 'unreviewed';
    case Ignored = 'ignored';
}
