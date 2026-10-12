<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An immutable Stripe Price MotionGrove created and verified itself. */
class PlatformStripePrice extends Model
{
    protected $table = 'platform_stripe_prices';

    protected $guarded = ['*'];

    protected $casts = [
        'amount_minor' => 'integer',
        'interval_count' => 'integer',
        'livemode' => 'boolean',
        'verified_at' => 'datetime',
    ];
}
