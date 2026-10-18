<?php

namespace App\Models;

use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta Ads Module V1 contract 24 §5 / §7 — the 1:1 Ads-domain detail of a
 * pause/resume. It deliberately has NO status, failure or idempotency
 * attribute: those are owned by the linked business_meta_operations ledger
 * row; read a mutation's outcome through `operation()`.
 */
class MetaAdsMutation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'target_type' => MetaAdsMutationTargetType::class,
        'requested_state' => MetaAdsRequestedState::class,
        'target_local_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdsAccount::class, 'meta_ads_account_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(BusinessMetaOperation::class, 'business_meta_operation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('meta_ads_account_id', $accountId);
    }
}
