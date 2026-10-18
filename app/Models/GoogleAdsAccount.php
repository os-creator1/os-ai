<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Google Ads Module V1 contract §3 — the SELECTED Ads customer for a
 * Business (one row per Business) and its Ads configuration.
 *
 * Written only by GoogleAdsAccountSelector (identity columns, taken from a
 * freshly-derived candidate), the sync coordinator (sync bookkeeping) and the
 * Settings service (the two targets). Never mass-assigned from a request:
 * login_customer_id, currency and time zone must come from the server.
 *
 * Money columns are micros in the account currency; NULL target = "no target".
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $business_google_connection_id
 * @property string $customer_id
 * @property ?string $login_customer_id
 * @property ?string $descriptive_name
 * @property string $currency_code
 * @property string $time_zone
 * @property bool $is_test_account
 * @property ?int $monthly_budget_target_micros
 * @property ?int $target_cpl_micros
 */
class GoogleAdsAccount extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'is_test_account' => 'boolean',
        'selected_at' => 'datetime',
        'monthly_budget_target_micros' => 'integer',
        'target_cpl_micros' => 'integer',
        'last_sync_started_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'data_through_date' => 'date',
        'sync_claimed_at' => 'datetime',
        'manual_refresh_requested_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BusinessGoogleConnection::class, 'business_google_connection_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(GoogleAdsCampaign::class, 'google_ads_account_id');
    }

    public function adGroups(): HasMany
    {
        return $this->hasMany(GoogleAdsAdGroup::class, 'google_ads_account_id');
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(GoogleAdsKeyword::class, 'google_ads_account_id');
    }

    public function searchTerms(): HasMany
    {
        return $this->hasMany(GoogleAdsSearchTerm::class, 'google_ads_account_id');
    }

    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(GoogleAdsDailyMetric::class, 'google_ads_account_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(GoogleAdsSyncRun::class, 'google_ads_account_id');
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(GoogleAdsMutation::class, 'google_ads_account_id');
    }
}
