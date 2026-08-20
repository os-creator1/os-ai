<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only durable audit trail for workspace_plan_catalog pricing
 * mutations (RFC-004 Amendment 2 §8) — created_at only, no updated_at, no
 * row is ever updated after insert.
 *
 * from_currency_id/to_currency_id are deliberately plain scalars with no
 * foreign key (Amendment 2 §8, locked) — historical evidence must survive
 * a later currency lifecycle change or deletion.
 */
class WorkspacePlanCatalogPricingChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'workspace_plan_catalog_pricing_changes';

    protected $fillable = [
        'workspace_plan_catalog_id',
        'actor_user_id',
        'reason',
        'from_price',
        'to_price',
        'from_currency_id',
        'to_currency_id',
        'from_additional_business_slot_price_ratio',
        'to_additional_business_slot_price_ratio',
    ];

    protected $casts = [
        'from_price' => 'decimal:2',
        'to_price' => 'decimal:2',
        'from_additional_business_slot_price_ratio' => 'decimal:4',
        'to_additional_business_slot_price_ratio' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'workspace_plan_catalog_id');
    }
}
