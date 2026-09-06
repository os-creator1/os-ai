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

    protected $fillable = [
        'workspace_id',
        'sending_server_id',
        'provider',
        'sender_number',
        'status',
        'created_by_user_id',
    ];

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
