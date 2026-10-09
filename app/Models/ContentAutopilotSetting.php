<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content Autopilot (Contract 25) — one row per Business. See the migration for what lives here and why.
 */
class ContentAutopilotSetting extends Model
{
    protected $fillable = [
        'business_id',
        'enabled',
        'enabled_at',
        'enabled_by_user_id',
        'paused_reason',
        'profile',
        'profile_completed_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'enabled_by_user_id' => 'integer',
        'profile' => 'array',
        'profile_completed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
