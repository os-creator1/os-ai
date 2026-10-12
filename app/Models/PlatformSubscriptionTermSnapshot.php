<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of a subscription's append-only commercial history: the price,
 * cycle, trial and offer terms it was on from `effective_at`. Never updated.
 */
class PlatformSubscriptionTermSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'platform_subscription_term_snapshots';

    protected $guarded = ['*'];

    protected $casts = [
        'terms' => 'array',
        'effective_at' => 'datetime',
    ];

    public function subscription(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'platform_subscription_id');
    }
}
