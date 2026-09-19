<?php

namespace App\Models;

use App\Enums\Documents\PaymentScheduleItemKind;
use App\Enums\Documents\PaymentScheduleItemStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.9 — a payment schedule item belongs to a
 * document VERSION (never the document). Its COMMERCIAL TERMS — sequence,
 * kind, amount_minor, currency_code, due_at — freeze when that version is
 * issued (§5.3.1) and are the only mass-assignable columns here. Its
 * PROGRESS fields (status, paid_at, reminder_*) are mutable for the currently
 * payable version and are written only by the later canonical managers.
 *
 * There is deliberately no `document()` shortcut relation: every schedule
 * read resolves through an exact Document Version, and only the schedule of
 * business_documents.current_version_id is payable (§5.9, §7.2).
 */
class BusinessDocumentPaymentScheduleItem extends Model
{
    use HasUid;

    protected $fillable = [
        'business_document_version_id',
        'sequence',
        'kind',
        'amount_minor',
        'currency_code',
        'due_at',
    ];

    protected $casts = [
        'kind' => PaymentScheduleItemKind::class,
        'status' => PaymentScheduleItemStatus::class,
        'sequence' => 'integer',
        'amount_minor' => 'integer',
        'reminder_count' => 'integer',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'reminder_last_sent_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentVersion::class, 'business_document_version_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessDocumentPayment::class, 'schedule_item_id');
    }
}
