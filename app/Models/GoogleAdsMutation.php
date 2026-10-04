<?php

namespace App\Models;

use App\Enums\GoogleAds\GoogleAdsMutationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Ads Module V1 contract §6 / D8 — the 1:1 Ads-domain detail of a
 * mutation. It deliberately has NO status, failure or idempotency attribute:
 * those are owned by the linked business_google_operations ledger row, and a
 * second copy here would be a second source of truth. Read a mutation's
 * outcome through `operation()`.
 */
class GoogleAdsMutation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'kind' => GoogleAdsMutationKind::class,
        'target_local_id' => 'integer',
        'params' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAdsAccount::class, 'google_ads_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BusinessGoogleOperation::class, 'business_google_operation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
