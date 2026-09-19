<?php

namespace App\Models;

use App\Enums\Documents\DocumentLineItemSource;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.4 — a line item belongs to a document
 * VERSION. Write-once: `created_at` only, no `updated_at`.
 *
 * `package_snapshot_uid` references Contract 16's package_snapshots by `uid`
 * (never `id`) and is deliberately not a foreign key (§5.4). The model does
 * not resolve it: rendering an issued document uses the line's own
 * denormalized copy, never a join into another module's current row shape.
 */
class BusinessDocumentLineItem extends Model
{
    use HasUid;

    const UPDATED_AT = null;

    protected $fillable = [
        'business_document_version_id',
        'position',
        'source',
        'package_snapshot_uid',
        'name',
        'description',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'currency_code',
    ];

    protected $casts = [
        'source' => DocumentLineItemSource::class,
        'position' => 'integer',
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'line_total_minor' => 'integer',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentVersion::class, 'business_document_version_id');
    }
}
