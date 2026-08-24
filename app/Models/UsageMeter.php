<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RFC-005 Amendment 1 §B — the economic/metering identity, additive to
 * PlatformFeature. meter_key/feature_key/business_id/currency_id are
 * immutable after creation; only active_rate_id/is_metered/description/
 * updated_by_user_id are mutable, and only through
 * UsageMeterRepository::update()'s narrow, whitelisted authority.
 */
class UsageMeter extends Model
{
    protected $table = 'usage_meters';

    protected $fillable = [
        'meter_key',
        'feature_key',
        'business_id',
        'currency_id',
        'is_metered',
        'active_rate_id',
        'description',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_metered' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function activeRate(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageRate::class, 'active_rate_id');
    }
}
