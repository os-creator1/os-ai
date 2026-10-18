<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Google Ads Module V1 contract §4 — a normalised ad group. Written only by
 * the sync (upsert on account + external_ad_group_id). Not user-addressable,
 * so it has no uid.
 */
class GoogleAdsAdGroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status' => GoogleAdsEntityStatus::class,
        'last_synced_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsCampaign::class, 'google_ads_campaign_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(GoogleAdsKeyword::class, 'google_ads_ad_group_id');
    }
}
