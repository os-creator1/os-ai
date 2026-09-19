<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 16 §5.2 — a sparse per-Location deviation from a
 * catalog item's Business-wide default. No row for a given
 * (catalog_item_id, business_location_id) pair means enabled at the
 * Business-wide default price. Casts/relations only in this sub-slice;
 * CRUD authority belongs to Sub-slice C's
 * `CatalogItemLocationOverrideManager`.
 */
class CatalogItemLocationOverride extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'business_location_id',
        'is_enabled',
        'price_minor_override',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'price_minor_override' => 'integer',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }
}
