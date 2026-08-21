<?php

namespace App\Models;

use App\Enums\Usage\AddonPurchaseStatus;
use App\Enums\Usage\TransitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageAddonPurchaseTransition extends Model
{
    protected $table = 'business_usage_addon_purchase_transitions';

    public $timestamps = false;

    protected $fillable = [
        'purchase_id',
        'from_status',
        'to_status',
        'source',
        'provider_event_id',
        'actor_user_id',
        'failure_reason',
        'created_at',
    ];

    protected $casts = [
        'from_status' => AddonPurchaseStatus::class,
        'to_status' => AddonPurchaseStatus::class,
        'source' => TransitionSource::class,
        'created_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(BusinessUsageAddonPurchase::class, 'purchase_id');
    }
}
