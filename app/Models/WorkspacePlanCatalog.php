<?php

namespace App\Models;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspacePlanCatalog extends Model
{
    protected $table = 'workspace_plan_catalog';

    protected $fillable = [
        'tier',
        'display_name',
        'price',
        'currency_id',
        'billing_cycle',
        'business_slot_included',
        'business_slot_max',
        'unlimited_business_slots',
        'additional_business_slot_price_ratio',
        'is_active',
    ];

    protected $casts = [
        'tier' => WorkspacePlanTier::class,
        'price' => 'decimal:2',
        'business_slot_included' => 'integer',
        'business_slot_max' => 'integer',
        'unlimited_business_slots' => 'boolean',
        'additional_business_slot_price_ratio' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(WorkspacePlanFeature::class, 'workspace_plan_catalog_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkspacePlanAssignment::class, 'workspace_plan_catalog_id');
    }
}
