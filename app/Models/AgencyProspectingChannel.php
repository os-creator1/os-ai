<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyProspectingChannel extends Model
{
    use HasUid;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    /** Credentials wiped; the row is kept only so history can still name the sender. */
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'workspace_id',
        'sending_server_id',
        'provider',
        'sender_number',
        'status',
        'created_by_user_id',
        'is_default_sender',
        'webhook_public_key',
        'credentials_verified_at',
        'webhook_verified_at',
        'disconnected_at',
        'last_error_code',
        'sms_capability_confirmed_at',
    ];

    protected $casts = [
        'is_default_sender' => 'boolean',
        'webhook_public_key' => \App\Casts\SealedSecretCast::class,
        'credentials_verified_at' => 'datetime',
        'webhook_verified_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'sms_capability_confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The database-enforced "one live owner per number" key (see the migration): the digits
        // of the sender number while the channel is live, NULL once it is disconnected.
        static::saving(function (self $channel): void {
            $digits = preg_replace('/\\D/', '', (string) $channel->sender_number);
            $channel->sender_key = $channel->status === self::STATUS_DISCONNECTED || $digits === '' ? null : $digits;
        });
    }

    protected $hidden = ['webhook_public_key'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sendingServer(): BelongsTo
    {
        return $this->belongsTo(SendingServer::class, 'sending_server_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
