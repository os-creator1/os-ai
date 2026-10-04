<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Platform Owner announcement. Lifecycle: draft -> scheduled -> published ->
 * expired, or cancelled from draft/scheduled/published. Written only by
 * App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager.
 */
class PlatformAnnouncement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const CHANNELS = ['banner', 'notification', 'email'];
    public const SEVERITIES = ['info', 'success', 'warning', 'critical'];

    protected $table = 'platform_announcements';

    protected $fillable = [
        'uid', 'title', 'body', 'severity', 'channels', 'audience', 'status', 'publish_at', 'expires_at',
        'published_at', 'recipients_total', 'recipients_done', 'created_by_user_id', 'source_run_id',
    ];

    protected $casts = [
        'channels' => 'array',
        'audience' => 'array',
        'publish_at' => 'datetime',
        'expires_at' => 'datetime',
        'published_at' => 'datetime',
        'recipients_total' => 'integer',
        'recipients_done' => 'integer',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(PlatformAnnouncementReceipt::class, 'announcement_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
