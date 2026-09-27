<?php

namespace App\Models;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PhoneNumberType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slice 3 §4.2 — one managed phone number, belonging to exactly one
 * identity. The owning Business is resolved by joining through that
 * identity, so ownership has a single source of truth.
 *
 * phone_number is always stored in canonical E.164 (§4.9); there is no
 * second, differently-formatted copy. Carries no credential (T-MSG-4).
 *
 * active_or_pending_phone_number and active_primary_identity_id are MySQL
 * STORED generated columns — never assignable, deliberately absent from
 * $fillable.
 *
 * `number_type` (text messaging setup/compliance hub) decides which
 * carrier registration regime applies to this number — 10DLC for
 * `local`, toll-free verification for `toll_free` — never both.
 *
 * Phone Numbers + A2P lane — the five lifecycle columns (next_renewal_at,
 * renewal_warning_sent_at, suspended_at, grace_expires_at,
 * release_notice_sent_at) are deliberately absent from $fillable:
 * NumberLifecycleManager is the single writer for all of them, exactly
 * the same discipline ProvisioningIncidentRecorder and
 * PortOutRequestManager already apply to their own resolution/cancellation
 * columns.
 */
class BusinessMessagingNumber extends Model
{
    protected $table = 'business_messaging_numbers';

    protected $fillable = [
        'business_messaging_identity_id',
        'phone_number',
        'number_type',
        'provider_number_reference',
        'status',
        'is_primary',
        'activated_at',
        'released_at',
    ];

    protected $casts = [
        'business_messaging_identity_id' => 'integer',
        'number_type' => PhoneNumberType::class,
        'status' => BusinessMessagingNumberStatus::class,
        'is_primary' => 'boolean',
        'activated_at' => 'datetime',
        'released_at' => 'datetime',
        'next_renewal_at' => 'datetime',
        'renewal_warning_sent_at' => 'datetime',
        'suspended_at' => 'datetime',
        'grace_expires_at' => 'datetime',
        'release_notice_sent_at' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingIdentity::class, 'business_messaging_identity_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Suspended;
    }

    public function isInGracePeriod(): bool
    {
        return $this->isSuspended() && $this->grace_expires_at !== null && $this->grace_expires_at->isFuture();
    }

    public function graceHasExpired(): bool
    {
        return $this->isSuspended() && $this->grace_expires_at !== null && ! $this->grace_expires_at->isFuture();
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', BusinessMessagingNumberStatus::Suspended->value);
    }
}
