<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RFC-005 Amendment 1 §C — append-only audit of a UsageMeter's own
 * metering-state changes (is_metered false<->true), scoped to meter
 * identity. Mirrors platform_feature_usage_classification_transitions'
 * own shape. Never written by Slice 1 — no legacy writer exists for this
 * table.
 */
class UsageMeterTransition extends Model
{
    public $timestamps = false;

    protected $table = 'usage_meter_transitions';

    protected $fillable = [
        'meter_key',
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
