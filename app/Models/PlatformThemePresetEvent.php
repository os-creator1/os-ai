<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Design System M2 Slice 1 contract §6.1/§9 item 6. Append-only,
 * immutable preset lifecycle audit trail. preset_id is the internal
 * numeric reference (locked decision: never uid here — uid is a
 * route-binding concern, not an internal audit-log concern).
 */
class PlatformThemePresetEvent extends Model
{
    public const EVENT_CREATED = 'created';
    public const EVENT_DUPLICATED = 'duplicated';
    public const EVENT_EDITED = 'edited';
    public const EVENT_RENAMED = 'renamed';
    public const EVENT_ACTIVATED = 'activated';
    public const EVENT_ROLLED_BACK = 'rolled_back';
    public const EVENT_DELETED = 'deleted';

    protected $table = 'platform_theme_preset_events';

    public $timestamps = false;

    protected $fillable = [
        'preset_id',
        'event_type',
        'metadata_json',
        'actor_user_id',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];
}
