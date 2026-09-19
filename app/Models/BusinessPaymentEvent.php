<?php

namespace App\Models;

use App\Enums\Documents\BusinessPaymentEventState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Implementation Contract 17 §5.8 — a lane-B webhook ingress row. NOT
 * payment_provider_events (lane D, §4.4): no wallet columns, no funding-attempt
 * attribution, and its own Connect webhook secret and route.
 *
 * Only intake identity and the encrypted payload are mass-assignable. The
 * claim/lease machinery (state, attempts, processing_started_at,
 * lease_expires_at, last_attempt_at, completed_at, last_error) is written by
 * the later atomic conditional UPDATEs of §8.2, never by mass assignment.
 * `last_error` holds an exception CLASS or reason code, never a provider
 * message. `payload_encrypted` uses Laravel's encrypted cast and is hidden.
 *
 * No `uid`/`HasUid` here: the contract's §5.8 schema has none — event identity
 * is (stripe_account_id, provider_event_id).
 */
class BusinessPaymentEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'business_stripe_connection_id',
        'stripe_account_id',
        'provider_event_id',
        'event_type',
        'payload_encrypted',
        'payload_hash',
    ];

    protected $hidden = [
        'payload_encrypted',
    ];

    protected $casts = [
        'state' => BusinessPaymentEventState::class,
        'attempts' => 'integer',
        'payload_encrypted' => 'encrypted',
        'processing_started_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload_purged_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BusinessStripeConnection::class, 'business_stripe_connection_id');
    }
}
