<?php

namespace App\Models;

use App\Enums\PlatformOwner\PlatformAnnouncementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * THE Platform announcement: the single model and table behind the Platform Owner's
 * Announcements UI, the Platform Automations "send announcement" action, the delivery
 * receipts, the banner and the notification feed. Lifecycle: draft -> scheduled ->
 * published -> expired, or cancelled from draft/scheduled/published.
 *
 * State is written only by App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager
 * (the Platform Owner façade, App\Library\PlatformOwner\Announcements\PlatformAnnouncementManager,
 * adds authority, validation messages and audit on top and never writes state itself).
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
        'published_at', 'recipients_total', 'recipients_done', 'delivery_ref', 'created_by_user_id', 'source_run_id',
    ];

    protected $casts = [
        'status' => PlatformAnnouncementStatus::class,
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

    /** Stored status, with "published but past expires_at" reported as Expired (the banner stops at that instant, with or without the sweep). */
    public function effectiveStatus(): PlatformAnnouncementStatus
    {
        if ($this->status === PlatformAnnouncementStatus::Published
            && $this->expires_at !== null && $this->expires_at->isPast()) {
            return PlatformAnnouncementStatus::Expired;
        }

        return $this->status;
    }

    /** The scheduled send time (the same column the runtime publishes by). */
    public function getScheduledAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->publish_at;
    }

    /** 'all' | 'tiers' — the Platform Owner form's view of the audience. */
    public function audienceMode(): string
    {
        return in_array($this->audience['kind'] ?? 'everyone', ['tier', 'tiers'], true) ? 'tiers' : 'all';
    }

    /** @return list<string> */
    public function audienceTiers(): array
    {
        $audience = (array) $this->audience;

        return array_values(array_unique(array_filter(array_map('strval', (array) ($audience['tiers'] ?? (isset($audience['tier']) ? [$audience['tier']] : []))))));
    }

    /** The form's channels: banner + notification are "in the app". @return list<string> */
    public function uiChannels(): array
    {
        $channels = (array) $this->channels;
        $ui = [];

        if (array_intersect(['banner', 'notification'], $channels) !== []) {
            $ui[] = 'in_app';
        }
        if (in_array('email', $channels, true)) {
            $ui[] = 'email';
        }

        return $ui;
    }
}
