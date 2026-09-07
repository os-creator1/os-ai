<?php

namespace App\Models;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * GBP Slice A contract §11.1 — the Business-owned Google OAuth connection.
 *
 * refresh_token_encrypted uses Laravel's built-in `encrypted` cast, the
 * same and only precedent in this repository as
 * PaymentProviderEvent.payload_encrypted (contract §9.7). It is null in
 * every state except `active`.
 *
 * There is deliberately NO access-token attribute: an access token is
 * derived from the refresh token per unit of work and never persisted.
 *
 * $hidden covers the two attributes that must never be serialized into a
 * view, JSON response, log line, exception context, event payload or audit
 * record (contract §9.7, §27, security criterion G-4). This is defence in
 * depth — GBP code never passes a model to a view — but it means an
 * accidental toArray()/toJson() cannot leak them either.
 */
class BusinessGoogleConnection extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'business_id',
        'state',
        'refresh_token_encrypted',
        'granted_scopes',
        'google_account_email',
        'oauth_state_nonce',
        'oauth_state_expires_at',
        'refresh_claimed_at',
        'connected_at',
        'disconnected_at',
        'revoked_at',
        'last_refreshed_at',
        'failure_classification',
        'connected_by_user_id',
        'lock_version',
    ];

    protected $hidden = [
        'refresh_token_encrypted',
        'oauth_state_nonce',
    ];

    protected $casts = [
        'state' => GoogleConnectionState::class,
        'refresh_token_encrypted' => 'encrypted',
        'oauth_state_expires_at' => 'datetime',
        'refresh_claimed_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    /**
     * business_google_connections.uid is a database UUID column; HasUid's
     * default generateUid() uses uniqid(), which is neither a valid UUID
     * nor safe for anything security-adjacent. Mirrors
     * Website::generateUid() / Workspace::generateUid() exactly (contract
     * §9.3: "uniqid() is forbidden").
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessGoogleLocation::class, 'business_google_connection_id');
    }

    public function isActive(): bool
    {
        return $this->state === GoogleConnectionState::Active;
    }

    public function hasStoredAuthorization(): bool
    {
        return $this->refresh_token_encrypted !== null && $this->refresh_token_encrypted !== '';
    }
}
