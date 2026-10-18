<?php

namespace App\Models;

use App\Enums\MetaAds\MetaAdsLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta Ads Module V1 contract 24 §5.2 — one typed result per
 * (entity, day, action_type). A missing row is "unavailable", never zero.
 * `results` / `result_value` are decimal STRINGS (result_value may be null).
 */
class MetaAdsDailyResult extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'level' => MetaAdsLevel::class,
        'entity_id' => 'integer',
        'metric_date' => 'date',
        'results' => 'decimal:6',
        'result_value' => 'decimal:6',
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

    public function scopeOfActionType($query, string $actionType)
    {
        return $query->where('action_type', $actionType);
    }

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->whereBetween('metric_date', [$from, $to]);
    }
}
