<?php

namespace App\Models;

use App\Enums\Documents\StripeConnectionStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.7 — a lane-B connected-account record. Lane B
 * only (§4): unrelated to payment_provider_customers / business_payment_instruments.
 *
 * Connections are HISTORICAL records: a Business may hold many rows over its
 * lifetime but at most ONE live one (business_stripe_connections.
 * active_business_id, a STORED generated column with a UNIQUE key). This model
 * never writes that generated column.
 *
 * Only `business_id` and `stripe_account_id` are mass-assignable. Every
 * capability flag, status and sync stamp is provider truth owned by the later
 * StripeConnectGateway/manager (Sub-slice D). `stripe_account_id` is immutable
 * once provider identity is established and is never rewritten to a different
 * acct_ — old payments keep their exact connection id forever (§5.7).
 *
 * NO secret key or token is stored anywhere on this row.
 */
class BusinessStripeConnection extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'stripe_account_id',
    ];

    protected $casts = [
        'status' => StripeConnectionStatus::class,
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        'lock_version' => 'integer',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessDocumentPayment::class, 'business_stripe_connection_id');
    }

    public function paymentEvents(): HasMany
    {
        return $this->hasMany(BusinessPaymentEvent::class, 'business_stripe_connection_id');
    }
}
