<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A number explicitly set up for browser calling. Carries no credential: managed numbers use the
 * platform key from config, BYO numbers use their channel's sealed key, both resolved server-side only.
 */
class VoiceNumber extends Model
{
    public const SOURCE_MANAGED = 'managed';
    public const SOURCE_BYO = 'byo';

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_READY = 'ready';
    public const STATUS_MISCONFIGURED = 'misconfigured';

    protected $fillable = [
        'uid', 'business_id', 'workspace_id', 'source', 'messaging_number_id', 'agency_channel_id',
        'phone_number', 'webrtc_connection_id', 'call_control_app_id', 'voice_status', 'voice_error_code', 'voice_checked_at',
        'outbound_locked_at', 'inbound_status', 'inbound_error_code', 'inbound_user_id',
    ];

    protected $casts = ['voice_checked_at' => 'datetime', 'outbound_locked_at' => 'datetime'];

    public function inboundReady(): bool
    {
        return $this->isReady() && $this->inbound_status === 'ready';
    }

    public function isReady(): bool
    {
        return $this->voice_status === self::STATUS_READY;
    }

    /**
     * The registration scope this number's calls belong to: one SIP identity per (user, Business, scope).
     * A BYO scope is the Agency's channel AND the voice connection it uses, so numbers on different
     * connections never share an identity (or a browser client).
     */
    public function scopeKey(): string
    {
        return $this->source === self::SOURCE_BYO
            ? 'byo:' . (int) $this->agency_channel_id . ':' . substr(sha1((string) $this->webrtc_connection_id), 0, 12)
            : 'managed';
    }
}
