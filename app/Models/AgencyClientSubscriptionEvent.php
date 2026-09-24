<?php

namespace App\Models;

use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Lane C §C3.5 — one durably recorded lane-C Connect webhook event.
 *
 * `provider_event_id` is UNIQUE, which is what makes duplicate delivery a no-op
 * at the database rather than at the application's discretion.
 *
 * `connected_account_id` is captured at INTAKE, before resolution, because
 * §C4.1's account check must be made against durable evidence rather than
 * against a value re-read from a payload later.
 */
class AgencyClientSubscriptionEvent extends Model
{
    use HasUid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_client_subscription_id',
        'agency_workspace_id',
        'connected_account_id',
        'provider_event_id',
        'event_type',
        'provider_customer_id',
        'provider_subscription_id',
        'payload_encrypted',
        'payload_hash',
        'provider_created_at',
    ];

    protected $casts = [
        'state' => AgencySubscriptionEventState::class,
        'attempts' => 'integer',
        'processing_started_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload_purged_at' => 'datetime',
        'provider_created_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AgencyClientSubscription::class, 'agency_client_subscription_id');
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }
}
