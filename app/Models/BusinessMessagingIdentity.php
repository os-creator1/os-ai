<?php

namespace App\Models;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\MessagingProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Slice 3 §4.2 — the authoritative per-Business Messaging-Profile mapping.
 *
 * Carries no credential of any kind: under the §28.3 Candidate B
 * architecture there is exactly one platform Telnyx credential, held in
 * config/env only and never per-Business (T-MSG-4). It also carries no
 * provider-managed-account identifier and no JSON escape hatch.
 *
 * active_or_pending_business_id is a MySQL STORED generated column — never
 * assignable, and deliberately absent from $fillable.
 */
class BusinessMessagingIdentity extends Model
{
    protected $table = 'business_messaging_identities';

    protected $fillable = [
        'uid',
        'business_id',
        'provider',
        'status',
        'messaging_profile_id',
        'messaging_connection_id',
        'activated_at',
        'archived_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'provider' => MessagingProvider::class,
        'status' => BusinessMessagingIdentityStatus::class,
        'activated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(BusinessMessagingNumber::class, 'business_messaging_identity_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessMessagingIdentityStatus::Active;
    }
}
