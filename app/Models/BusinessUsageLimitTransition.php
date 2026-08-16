<?php

namespace App\Models;

use App\Enums\Usage\UsageLimitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only — never updated or deleted after insert.
 */
class BusinessUsageLimitTransition extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_limit_transitions';

    protected $fillable = [
        'business_id',
        'limit_type',
        'feature_key',
        'from_value_micro',
        'to_value_micro',
        'actor_user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'limit_type' => UsageLimitType::class,
        'from_value_micro' => 'integer',
        'to_value_micro' => 'integer',
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
