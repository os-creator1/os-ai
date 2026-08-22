<?php

namespace App\Models;

use App\Enums\Usage\RoundingRule;
use App\Enums\Usage\UsageReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageReservation extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_reservations';

    protected $fillable = [
        'business_id',
        'wallet_id',
        'feature_key',
        'meter_key',
        'period_key',
        'status',
        'reserved_amount_micro',
        'estimated_quantity',
        'rate_id',
        'rate_version',
        'retail_rate_micro',
        'provider_cost_micro',
        'rounding_rule',
        'idempotency_key',
        'correlation_key',
        'reserved_at',
        'expires_at',
        'committed_at',
        'released_at',
        'final_quantity',
        'final_amount_micro',
    ];

    protected $casts = [
        'status' => UsageReservationStatus::class,
        'reserved_amount_micro' => 'integer',
        'estimated_quantity' => 'decimal:6',
        'rate_version' => 'integer',
        'retail_rate_micro' => 'integer',
        'provider_cost_micro' => 'integer',
        'rounding_rule' => RoundingRule::class,
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'committed_at' => 'datetime',
        'released_at' => 'datetime',
        'final_quantity' => 'decimal:6',
        'final_amount_micro' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageWallet::class, 'wallet_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageRate::class, 'rate_id');
    }
}
