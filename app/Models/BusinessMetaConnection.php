<?php

namespace App\Models;

use App\Enums\MetaAds\MetaConnectionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §3 — the Business-owned Meta OAuth
 * connection (one row per Business).
 *
 * access_token_encrypted holds the long-lived Meta user token through
 * Laravel's `encrypted` cast; it is null in every state except `active`.
 * $hidden keeps it (and the OAuth state nonce) out of any accidental
 * toArray()/toJson(). No business logic lives here: transitions are owned by
 * the connection manager and validated by MetaConnectionState.
 */
class BusinessMetaConnection extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'state',
        'access_token_encrypted',
        'token_expires_at',
        'granted_scopes',
        'meta_user_id',
        'meta_user_name',
        'oauth_state_nonce',
        'oauth_state_expires_at',
        'connected_at',
        'disconnected_at',
        'revoked_at',
        'last_verified_at',
        'failure_classification',
        'connected_by_user_id',
        'lock_version',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'oauth_state_nonce',
    ];

    protected $casts = [
        'state' => MetaConnectionState::class,
        'access_token_encrypted' => 'encrypted',
        'token_expires_at' => 'datetime',
        'oauth_state_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    /**
     * uid is a database UUID column; HasUid's default uses uniqid(), which is
     * not a valid UUID. Same override as BusinessGoogleConnection.
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function account(): HasOne
    {
        return $this->hasOne(MetaAdsAccount::class, 'business_meta_connection_id');
    }

    public function isActive(): bool
    {
        return $this->state === MetaConnectionState::Active;
    }

    public function hasStoredAuthorization(): bool
    {
        return $this->access_token_encrypted !== null && $this->access_token_encrypted !== '';
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}
