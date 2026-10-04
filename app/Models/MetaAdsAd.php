<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §5 — a normalised ad with a read-only
 * creative summary. Nothing here is ever written back to Meta except `status`
 * after a confirmed pause/resume.
 */
class MetaAdsAd extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
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

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(MetaAdsAdSet::class, 'meta_ads_ad_set_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }
}
