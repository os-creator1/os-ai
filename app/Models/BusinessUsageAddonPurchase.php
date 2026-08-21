<?php

namespace App\Models;

use App\Enums\Usage\AddonPurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageAddonPurchase extends Model
{
    protected $table = 'business_usage_addon_purchases';

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'addon_key',
        'price_micro',
        'funding_attempt_id',
        'status',
        'requested_by_user_id',
        'completed_at',
        'created_at',
    ];

    protected $casts = [
        'price_micro' => 'integer',
        'status' => AddonPurchaseStatus::class,
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fundingAttempt(): BelongsTo
    {
        return $this->belongsTo(BusinessFundingAttempt::class, 'funding_attempt_id');
    }
}
