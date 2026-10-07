<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row of the Platform Owner action trail
 * (platform_admin_actions). Written only through PlatformAdminAuditLog.
 */
class PlatformAdminAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'platform_admin_actions';

    protected $fillable = [
        'actor_user_id', 'action', 'subject_type', 'subject_ref', 'summary', 'reason', 'payload',
    ];

    protected $casts = ['payload' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
