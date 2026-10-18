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
    ];

    protected $casts = [
        'is_default_sender' => 'boolean',
        'webhook_public_key' => \App\Casts\SealedSecretCast::class,
        'credentials_verified_at' => 'datetime',
        'webhook_verified_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

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
