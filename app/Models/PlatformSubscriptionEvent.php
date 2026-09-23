<?php

namespace App\Models;

use App\Enums\PlatformBilling\PlatformSubscriptionEventState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 21 §12 — a lane-A webhook ingress row.
 *
 * NOT lane B's `business_payment_events` and NOT lane D's
 * `payment_provider_events` (§2). Lane A has its own endpoint, its own signing
 * secret and its own table, so an event about a Workspace's platform
 * subscription can never be resolved against a Business document or a usage
 * wallet.
 *
 * Only intake identity and the encrypted payload are mass-assignable. The
 * claim/lease machinery is written by atomic conditional UPDATEs, never by
 * mass assignment. `last_error` holds an exception CLASS or a reason CODE,
 * never a provider message.
 */
class PlatformSubscriptionEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'provider_event_id',
        'event_type',
        'provider_customer_id',
        'provider_subscription_id',
        'payload_encrypted',
        'payload_hash',
        'provider_created_at',
    ];

    protected $hidden = [
        'payload_encrypted',
    ];

    protected $casts = [
        'state' => PlatformSubscriptionEventState::class,
        'attempts' => 'integer',
        'payload_encrypted' => 'encrypted',
        'processing_started_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload_purged_at' => 'datetime',
        'provider_created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'platform_subscription_id');
    }
}
