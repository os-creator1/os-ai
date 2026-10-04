<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Google Ads Module V1 contract §4 — a normalised campaign. Written only by
 * the sync (upsert on account + external_campaign_id) and, after a confirmed
 * provider success, by the mutation service (status). `uid` is what a request
 * may name; it is always re-resolved inside the Business's selected account.
 *
 * `budget_amount_micros` is Google's DAILY campaign budget (display fact);
 * it is not the Business monthly target.
 */
class GoogleAdsCampaign extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'status' => GoogleAdsEntityStatus::class,
        'budget_amount_micros' => 'integer',
        'budget_shared' => 'boolean',
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

    public function adGroups(): HasMany
    {
        return $this->hasMany(GoogleAdsAdGroup::class, 'google_ads_campaign_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(GoogleAdsKeyword::class, 'google_ads_campaign_id');
    }

    /** `customers/{customer}/campaigns/{id}` — derived, never trusted from a request. */
    public function resourceName(string $customerId): string
    {
        return 'customers/' . $customerId . '/campaigns/' . $this->external_campaign_id;
    }
}
