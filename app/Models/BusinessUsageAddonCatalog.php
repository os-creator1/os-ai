<?php

namespace App\Models;

use App\Enums\Usage\AddonFulfillmentMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUsageAddonCatalog extends Model
{
    protected $table = 'business_usage_addon_catalog';

    protected $fillable = [
        'addon_key',
        'display_name',
        'price_micro',
        'currency_id',
        'fulfillment_mode',
        'is_active',
    ];

    protected $casts = [
        'price_micro' => 'integer',
        'fulfillment_mode' => AddonFulfillmentMode::class,
        'is_active' => 'boolean',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
