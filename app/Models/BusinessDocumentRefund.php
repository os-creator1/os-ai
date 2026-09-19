<?php

namespace App\Models;

use App\Enums\Documents\BusinessDocumentRefundStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.9/§8.7 — a refund against a captured payment.
 * Lane B only (§4). Refund issuance does not exist anywhere in the repository
 * today; it is built in Sub-slice F, not here.
 *
 * Admission is concurrency-safe under the payment row lock and reserves
 * against BOTH pending and succeeded refunds (§8.7) — none of that lives on
 * this model. Only request identity/amount fields are mass-assignable;
 * provider_refund_id, status and succeeded_at are provider truth owned by the
 * later refund manager.
 */
class BusinessDocumentRefund extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'business_document_payment_id',
        'local_idempotency_key',
        'amount_minor',
        'reason',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'status' => BusinessDocumentRefundStatus::class,
        'amount_minor' => 'integer',
        'succeeded_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentPayment::class, 'business_document_payment_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
