<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageRateActivation extends Model
{
    public $timestamps = false;

    protected $table = 'business_usage_rate_activations';

    protected $fillable = [
        'meter_key',
        'rate_id',
        'activated_at',
        'activated_by_user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function rate(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageRate::class, 'rate_id');
    }
}
