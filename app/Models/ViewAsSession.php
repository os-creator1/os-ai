<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Durable audit row for one View-as-client session (Customer Experience
 * contract §5.5): who viewed which Business, in which Workspace, from when,
 * until when, why it ended, and every prohibited action refused meanwhile.
 * Never session-only; the session merely remembers the row's uid.
 */
class ViewAsSession extends Model
{
    public const END_REASON_EXIT = 'exit';
    public const END_REASON_EXPIRED = 'expired';
    public const END_REASON_LOGOUT = 'logout';
    public const END_REASON_REPLACED = 'replaced';
    public const END_REASON_ACCESS_LOST = 'access_lost';

    protected $table = 'view_as_sessions';

    protected $fillable = [
        'uid',
        'actor_user_id',
        'workspace_id',
        'business_id',
        'reason',
        'started_at',
        'expires_at',
        'ended_at',
        'end_reason',
        'refusals',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'refusals' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ViewAsSession $session): void {
            if (empty($session->uid)) {
                $session->uid = (string) Str::uuid();
            }
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isEnded(): bool
    {
        return $this->ended_at !== null;
    }
}
