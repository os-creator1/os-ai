<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceRegistration extends Model
{
    /** A browser that has not heartbeated within this window is treated as offline for inbound. */
    public const ONLINE_WINDOW_SECONDS = 75;

    protected $fillable = [
        'uid', 'user_id', 'business_id', 'scope_key', 'connection_id', 'telnyx_credential_id',
        'sip_username', 'last_seen_at', 'revoked_at',
    ];

    protected $hidden = ['telnyx_credential_id', 'sip_username'];

    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function isOnline(): bool
    {
        return $this->revoked_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }
}
