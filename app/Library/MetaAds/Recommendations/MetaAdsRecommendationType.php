<?php

namespace App\Library\MetaAds\Recommendations;

/**
 * Meta Ads Module V1 contract 24 §12 — the closed set of deterministic
 * recommendation fact types. The values are persisted by nothing in V1 but are
 * the stable identity a later Growth lane keys on (contract §13); changing one
 * is a contract change.
 */
enum MetaAdsRecommendationType: string
{
    case ZeroResultSpend = 'zero_result_spend';
    case CostPerResultAboveTarget = 'cost_per_result_above_target';
    case PacingOver = 'pacing_over';
    case PacingUnder = 'pacing_under';
    case HighFrequencyWeakResults = 'high_frequency_weak_results';
    case DeliveryIssue = 'delivery_issue';
    case StrongPerformer = 'strong_performer';
}
