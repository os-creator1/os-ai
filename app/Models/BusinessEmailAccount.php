<?php

namespace App\Models;

use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A Business's own connected mailbox (Google or Microsoft). One per
 * Business; see the migration for why that row is also the default sender.
 *
 * `refresh_token_encrypted` uses the `encrypted` cast (repository
 * convention). The manager's conditional UPDATEs go through the query
 * builder and therefore encrypt explicitly with Crypt::encryptString().
 *
 * $hidden keeps the credential and the live OAuth nonce out of any
 * toArray()/toJson()/log/event serialization — defence in depth.
 */
class BusinessEmailAccount extends Model
{
    protected $table = 'business_email_accounts';

    protected $fillable = [
        'business_id',
        'provider',
        'state',
        'mailbox_email',
        'external_account_id',
        'display_name',
        'connected_by_user_id',
    ];

    protected $hidden = [
        'refresh_token_encrypted',
        'oauth_state_nonce',
    ];

    protected $casts = [
        'provider' => BusinessEmailProviderType::class,
        'state' => BusinessEmailAccountState::class,
        'refresh_token_encrypted' => 'encrypted',
        'oauth_state_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if ($account->uid === null) {
                $account->uid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isActive(): bool
    {
        return $this->state === BusinessEmailAccountState::Active
            && ! empty($this->refresh_token_encrypted);
    }
}
