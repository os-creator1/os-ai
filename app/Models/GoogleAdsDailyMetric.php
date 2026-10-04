<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Ads Module V1 contract §4 / §9 — a per-day fact at campaign or
 * keyword level. Account KPIs sum `campaign` rows ONLY. Money is micros in the
 * account currency; `conversions` / `conversions_value` are decimal STRINGS
 * or null (no data is not zero).
 */
class GoogleAdsDailyMetric extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'level' => GoogleAdsMetricLevel::class,
        'metric_date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'interactions' => 'integer',
        'cost_micros' => 'integer',
        'conversions' => 'decimal:6',
        'conversions_value' => 'decimal:6',
        'last_synced_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }
}
