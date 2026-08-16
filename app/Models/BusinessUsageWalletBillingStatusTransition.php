<?php

namespace App\Models;

use App\Enums\Usage\BillingStatusTransitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only — never updated or deleted after insert.
 */
class BusinessUsageWalletBillingStatusTransition extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_wallet_billing_status_transitions';

    protected $fillable = [
        'wallet_id',
        'business_id',
        'from_status',
        'to_status',
        'source',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'source' => BillingStatusTransitionSource::class,
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageWallet::class, 'wallet_id');
    }
}
