<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Business's Zoom OAuth connection. Tokens use the encrypted cast and are hidden from serialization;
 * nothing here ever holds a Zoom password or a meeting start_url.
 */
class ZoomConnection extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_ACTIVE = 'active';
    public const STATE_FAILED = 'failed';
    public const STATE_REVOKED = 'revoked';

    protected $fillable = [
        'business_id', 'user_id', 'state', 'state_nonce', 'zoom_user_id', 'zoom_account_email', 'zoom_display_name',
        'access_token_encrypted', 'refresh_token_encrypted', 'access_expires_at', 'failure_count',
        'last_error_code', 'last_error_at', 'connected_at',
    ];

    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted', 'state_nonce'];

    protected $casts = [
        'access_token_encrypted' => 'encrypted',
        'refresh_token_encrypted' => 'encrypted',
        'access_expires_at' => 'datetime',
        'last_error_at' => 'datetime',
        'connected_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE && ! blank($this->refresh_token_encrypted);
    }
}
