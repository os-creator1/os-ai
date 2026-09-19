<?php

namespace App\Models;

use App\Enums\Documents\DocumentVersionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.3 — the immutability boundary.
 *
 * After issue, COMMERCIAL CONTENT (content, content_hash, totals, currency,
 * line items, schedule commercial terms) is immutable forever; `state` may
 * make exactly one authorized transition, issued -> superseded (§5.3.1). This
 * model therefore does NOT claim the row is physically update-impossible — it
 * cannot be, because state transitions and a DRAFT is edited in place. The
 * guarantee is enforced by production code never modifying an issued
 * version's commercial fields, proved by a source-boundary test in the
 * sub-slices that add the write paths (B/C), not by anything here.
 *
 * `state`, `content_hash`, `issued_at`, `superseded_at` are not mass-assignable
 * (owned by the later manager), and `draft_guard` is a STORED generated column
 * that must never be written at all.
 */
class BusinessDocumentVersion extends Model
{
    use HasUid;

    protected $fillable = [
        'business_document_id',
        'version_number',
        'content',
        'subtotal_minor',
        'total_minor',
        'currency_code',
        'schema_version',
        'created_by_user_id',
    ];

    protected $casts = [
        'state' => DocumentVersionState::class,
        'content' => 'array',
        'version_number' => 'integer',
        'subtotal_minor' => 'integer',
        'total_minor' => 'integer',
        'schema_version' => 'integer',
        'issued_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BusinessDocumentLineItem::class, 'business_document_version_id');
    }

    /**
     * §5.9 — the payment schedule belongs to the VERSION, never the document.
     */
    public function paymentScheduleItems(): HasMany
    {
        return $this->hasMany(BusinessDocumentPaymentScheduleItem::class, 'business_document_version_id');
    }
}
