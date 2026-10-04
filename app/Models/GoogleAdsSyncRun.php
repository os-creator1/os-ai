<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Google Ads Module V1 contract §4 / §5 — one sync attempt. `failure_code` is
 * a safe code only; no provider payload is ever stored. Call counts live on
 * the linked ledger operation.
 */
class GoogleAdsSyncRun extends Model
{
    use HasUid;

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'state' => GoogleAdsSyncRunState::class,
        'trigger' => GoogleAdsSyncTrigger::class,
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
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BusinessGoogleOperation::class, 'business_google_operation_id');
    }
}
