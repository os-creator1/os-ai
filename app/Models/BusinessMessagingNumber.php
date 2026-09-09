<?php

namespace App\Models;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
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
 */
class BusinessMessagingNumber extends Model
{
    protected $table = 'business_messaging_numbers';

    protected $fillable = [
        'business_messaging_identity_id',
        'phone_number',
        'provider_number_reference',
        'status',
        'is_primary',
        'activated_at',
        'released_at',
    ];

    protected $casts = [
        'business_messaging_identity_id' => 'integer',
        'status' => BusinessMessagingNumberStatus::class,
        'is_primary' => 'boolean',
        'activated_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingIdentity::class, 'business_messaging_identity_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Active;
    }
}
