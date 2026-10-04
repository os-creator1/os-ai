<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Google Ads Module V1 contract §4 — a positive or negative keyword.
 * `external_criterion_id` is the resource-name TAIL (`{parentId}~{criterionId}`),
 * unique per account + level. `google_ads_ad_group_id` is null exactly for
 * campaign-level negatives. Written only by the sync and, after a confirmed
 * provider success, the mutation service.
 */
class GoogleAdsKeyword extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'match_type' => GoogleAdsMatchType::class,
        'status' => GoogleAdsEntityStatus::class,
        'level' => GoogleAdsKeywordLevel::class,
        'is_negative' => 'boolean',
        'quality_score' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
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
