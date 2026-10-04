<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §3 / §4 / §5 — the SELECTED Meta ad account
 * for a Business (one row per Business) and its Meta Ads configuration.
 *
 * Identity columns (ad_account_id, currency, time zone, status) must come
 * from a freshly derived candidate, never from a request, so the model is
 * guarded rather than fillable. Money columns are micros in the account
 * currency; NULL target = "no target"; NULL result_action_type = results are
 * unavailable (not zero). NULL selected_at = no selected account.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $business_meta_connection_id
 * @property string $ad_account_id
 * @property ?string $name
 * @property string $currency_code
 * @property string $time_zone
 * @property ?int $account_status
 * @property ?string $result_action_type
 * @property ?int $monthly_budget_target_micros
 * @property ?int $target_cost_per_result_micros
 */
class MetaAdsAccount extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'account_status' => 'integer',
        'selected_at' => 'datetime',
        'monthly_budget_target_micros' => 'integer',
        'target_cost_per_result_micros' => 'integer',
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
        return $this->belongsTo(BusinessMetaConnection::class, 'business_meta_connection_id');
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_user_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MetaAdsCampaign::class, 'meta_ads_account_id');
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(MetaAdsAdSet::class, 'meta_ads_account_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(MetaAdsAd::class, 'meta_ads_account_id');
    }

    public function dailyInsights(): HasMany
    {
        return $this->hasMany(MetaAdsDailyInsight::class, 'meta_ads_account_id');
    }

    public function dailyResults(): HasMany
    {
        return $this->hasMany(MetaAdsDailyResult::class, 'meta_ads_account_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MetaAdsSyncRun::class, 'meta_ads_account_id');
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(MetaAdsMutation::class, 'meta_ads_account_id');
    }

    public function isSelected(): bool
    {
        return $this->selected_at !== null;
    }

    public function scopeSelected($query)
    {
        return $query->whereNotNull('selected_at');
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}
