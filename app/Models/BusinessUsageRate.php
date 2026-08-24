<?php

namespace App\Models;

use App\Enums\Usage\RoundingRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fully immutable once inserted — no updated_at column exists (RFC-005
 * §11, M1 contract §6.2).
 */
class BusinessUsageRate extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_rates';

    protected $fillable = [
        'meter_key',
        'version',
        'retail_rate_micro',
        'provider_cost_micro',
        'unit_label',
        'rounding_rule',
        'currency_id',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'retail_rate_micro' => 'integer',
        'provider_cost_micro' => 'integer',
        'rounding_rule' => RoundingRule::class,
        'created_at' => 'datetime',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
