<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A free trial that has been reserved or used. Its three unique keys
 * (Workspace, normalized email, provider customer) are what stop a trial being
 * repeated across cancelled Checkouts, resubscriptions and duplicate accounts.
 */
class PlatformTrialClaim extends Model
{
    public const STATE_RESERVED = 'reserved';
    public const STATE_GRANTED = 'granted';

    protected $table = 'platform_trial_claims';

    protected $guarded = ['*'];

    protected $casts = [
        'reserved_until' => 'datetime',
        'granted_at' => 'datetime',
    ];
}
