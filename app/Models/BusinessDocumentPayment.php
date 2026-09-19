<?php

namespace App\Models;

use App\Enums\Documents\BusinessDocumentPaymentStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.9 — one payment ATTEMPT against a schedule
 * item on the Business's own connected Stripe account. Lane B only (§4).
 *
 * At most ONE live attempt exists per schedule item, enforced by the
 * `active_schedule_item_id` STORED generated column + UNIQUE key (§7.2). This
 * model never writes that column.
 *
 * There is NO `client_secret` attribute and there never will be: a
 * PaymentIntent client_secret is transient browser material that must never be
 * persisted, logged, or placed in an exception (§7.2.1, §11.8). Durable
 * identity is uid + provider_payment_intent_id + business_stripe_connection_id.
 *
 * `status` is OUR local vocabulary, mapped from the provider's in exactly one
 * gateway seam (§11.8). Only the identity/amount columns are mass-assignable;
 * status, provider ids, failure_code, succeeded_at and receipt_sent_at are
 * provider/lifecycle truth owned by the later PaymentManager and finalizer
 * (Sub-slice E) and are written under §7.0's lock order, never by mass
 * assignment.
 */
class BusinessDocumentPayment extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'business_document_id',
        'schedule_item_id',
        'business_stripe_connection_id',
        'local_idempotency_key',
        'amount_minor',
        'currency_code',
    ];

    protected $casts = [
        'status' => BusinessDocumentPaymentStatus::class,
        'amount_minor' => 'integer',
        'succeeded_at' => 'datetime',
        'receipt_sent_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentPaymentScheduleItem::class, 'schedule_item_id');
    }

    /**
     * The exact HISTORICAL connection this payment was created on — kept
     * forever, and the account refunds and webhooks target (§5.7).
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BusinessStripeConnection::class, 'business_stripe_connection_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(BusinessDocumentRefund::class, 'business_document_payment_id');
    }
}
