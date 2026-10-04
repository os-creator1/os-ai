<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §5 — a normalised ad set.
 *
 * reach_7d / frequency_7d are the ad set's OWN trailing-7-day figures (not
 * additive, never summed); frequency_7d is a decimal STRING or null.
 * targeting_summary is a server-built string, never raw targeting.
 */
class MetaAdsAdSet extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'daily_budget_minor' => 'integer',
        'lifetime_budget_minor' => 'integer',
        'reach_7d' => 'integer',
        'frequency_7d' => 'decimal:4',
        'frequency_window_end' => 'date',
        'last_synced_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdsAccount::class, 'meta_ads_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MetaAdsCampaign::class, 'meta_ads_campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(MetaAdsAd::class, 'meta_ads_ad_set_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }
}
