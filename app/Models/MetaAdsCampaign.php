<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §5 — a normalised campaign. Written only by
 * the sync (upsert on account + external_campaign_id) and, after a confirmed
 * provider success, by the mutation service (status). `uid` is what a request
 * may name; it is always re-resolved inside the Business's selected account.
 *
 * Budgets are integer minor units of the account currency (display facts);
 * NULL = Meta returned none.
 */
class MetaAdsCampaign extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'daily_budget_minor' => 'integer',
        'lifetime_budget_minor' => 'integer',
        'budget_remaining_minor' => 'integer',
        'start_time' => 'datetime',
        'stop_time' => 'datetime',
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

    public function adSets(): HasMany
    {
        return $this->hasMany(MetaAdsAdSet::class, 'meta_ads_campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(MetaAdsAd::class, 'meta_ads_campaign_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }
}
