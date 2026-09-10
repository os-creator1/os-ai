<?php

namespace App\Models;

use App\Enums\Entitlement\PlatformFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slice 3 §4.8 — an RFC-005-owned measurement record: quantity only, never
 * a price.
 *
 * Written exclusively by EloquentBusinessUsageMeasurementRepository, reached
 * only through UsageWalletManager::recordMeasurement(). No class under
 * app/Library/Messaging/** holds a reference to this model (T-MSG-48).
 *
 * quantity is kept as a decimal-safe string, following UsageWalletManager's
 * own existing convention — never a native float.
 */
class BusinessUsageMeasurement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'business_usage_measurements';

    protected $fillable = [
        'business_id',
        'feature_key',
        'quantity',
        'unit',
        'transport_marker',
        'idempotency_key',
        'occurred_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'feature_key' => PlatformFeature::class,
        'quantity' => 'string',
        'occurred_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
