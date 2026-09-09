<?php

namespace App\Models;

use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\WebhookRejectionReason;
use Illuminate\Database\Eloquent\Model;

/**
 * Slice 3 §4.2/§4.6 — a bounded, minimized security/rejection audit row.
 *
 * There is deliberately no business_id: a row here is by definition a case
 * where authoritative attribution could not be established. The two
 * *_resolved_identity_id columns are admin-only debugging hints for the
 * conflicting case, never authoritative attribution and never joined into
 * conversation data.
 *
 * No raw body is ever stored — only a SHA-256 fingerprint.
 */
class MessagingWebhookRejection extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'messaging_webhook_rejections';

    protected $fillable = [
        'reason',
        'provider',
        'payload_hash',
        'messaging_profile_id',
        'destination_number',
        'profile_resolved_identity_id',
        'number_resolved_identity_id',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'reason' => WebhookRejectionReason::class,
        'provider' => MessagingProvider::class,
        'occurrence_count' => 'integer',
        'profile_resolved_identity_id' => 'integer',
        'number_resolved_identity_id' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
