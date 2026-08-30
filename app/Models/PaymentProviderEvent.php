<?php

namespace App\Models;

use App\Enums\Usage\PaymentProvider;
use App\Enums\Usage\ProviderEventState;
use Illuminate\Database\Eloquent\Model;

/**
 * M3 contract §21.5 — payload_encrypted uses Laravel's built-in `encrypted`
 * cast, the first real use of that cast in this repository. Purged (set
 * null) by PurgeExpiredWebhookPayloads once a terminal event's retention
 * window has passed (M3 contract §14).
 */
class PaymentProviderEvent extends Model
{
    protected $table = 'payment_provider_events';

    public $timestamps = false;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'provider_object_id',
        'payload_encrypted',
        'payload_hash',
        'payload_purged_at',
        'state',
        'attempts',
        'processing_started_at',
        'lease_expires_at',
        'last_attempt_at',
        'last_error',
        'received_at',
        'completed_at',
        'disposed_at',
        'disposed_by_user_id',
        'disposition_note',
        'business_id',
        'funding_attempt_id',
        'normalized_outcome',
        'normalized_status',
        'normalized_reported_amount_micro',
        'normalized_outcome_delta_micro',
        'normalized_wallet_delta_micro',
        'normalized_policy_excess_micro',
        'normalized_currency_code',
        'normalized_reason',
        'normalized_recorded_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'payload_encrypted' => 'encrypted',
        'state' => ProviderEventState::class,
        'attempts' => 'integer',
        'processing_started_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
        'disposed_at' => 'datetime',
        'payload_purged_at' => 'datetime',
        'normalized_recorded_at' => 'datetime',
    ];
}
