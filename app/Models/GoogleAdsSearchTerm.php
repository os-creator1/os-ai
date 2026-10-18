<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Ads Module V1 contract §4 / §12 — one per-day search-term fact.
 * `conversions` / `conversions_value` are decimal STRINGS, or null when
 * Google returned none (null is not zero). `review_state` is the owner's
 * classification and is never overwritten by the sync.
 */
class GoogleAdsSearchTerm extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'metric_date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost_micros' => 'integer',
        'conversions' => 'decimal:6',
        'conversions_value' => 'decimal:6',
        'targeting_status' => GoogleAdsSearchTermStatus::class,
        'review_state' => GoogleAdsSearchTermReviewState::class,
        'last_synced_at' => 'datetime',
    ];

    /** The key shared by every row of the same term; matches the stored term_hash. */
    public static function hashTerm(string $term): string
    {
        return hash('sha256', mb_strtolower(trim($term)));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsCampaign::class, 'google_ads_campaign_id');
    }

    public function adGroup(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAdGroup::class, 'google_ads_ad_group_id');
    }
}
