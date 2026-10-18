<?php

namespace App\Models;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §6 — one sync attempt. `failure_code` is a
 * safe code only; no provider payload is ever stored. Call counts live on the
 * linked ledger operation.
 */
class MetaAdsSyncRun extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'state' => MetaAdsSyncRunState::class,
        'trigger' => MetaAdsSyncTrigger::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'data_through_date' => 'date',
        'rows_counted' => 'integer',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdsAccount::class, 'meta_ads_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BusinessMetaOperation::class, 'business_meta_operation_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('state', [MetaAdsSyncRunState::Queued->value, MetaAdsSyncRunState::Running->value]);
    }
}
