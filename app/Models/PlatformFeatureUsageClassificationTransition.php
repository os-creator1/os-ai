<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only; never written by any M1 code path (M1 contract §6.5).
 */
class PlatformFeatureUsageClassificationTransition extends Model
{
    public $timestamps = false;

    protected $table = 'platform_feature_usage_classification_transitions';

    protected $fillable = [
        'feature_key',
        'from_is_metered',
        'to_is_metered',
        'from_active_rate_id',
        'to_active_rate_id',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'from_is_metered' => 'boolean',
        'to_is_metered' => 'boolean',
        'created_at' => 'datetime',
    ];
}
