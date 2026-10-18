<?php

namespace App\Models;

use App\Enums\MetaAds\MetaAdsLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta Ads Module V1 contract 24 §5 — a per-day delivery fact at campaign,
 * ad set or ad level (entity_id = local id for that level). Account KPIs sum
 * `campaign` rows ONLY. spend_micros is BIGINT micros in the account
 * currency; link_clicks is null when Meta returned none (not zero).
 */
class MetaAdsDailyInsight extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'level' => MetaAdsLevel::class,
        'entity_id' => 'integer',
        'metric_date' => 'date',
        'spend_micros' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'link_clicks' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdsAccount::class, 'meta_ads_account_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }

    public function scopeAtLevel($query, MetaAdsLevel $level)
    {
        return $query->where('level', $level->value);
    }

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereBetween('metric_date', [$from, $to]);
    }
}
