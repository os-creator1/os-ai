<?php

namespace App\Models;

use App\Enums\PlatformOwner\PlatformAnnouncementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlatformAnnouncement extends Model
{
    protected $table = 'platform_announcements';

    protected $fillable = [
        'title', 'body', 'status', 'audience', 'audience_tiers', 'channels',
        'scheduled_at', 'published_at', 'expires_at', 'cancelled_at',
        'created_by', 'updated_by', 'delivery_ref',
    ];

    protected $casts = [
        'status' => PlatformAnnouncementStatus::class,
        'audience_tiers' => 'array',
        'channels' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->uid = $a->uid ?: (string) Str::uuid();
        });
    }

    /** Stored status, with "published but past expires_at" reported as Expired. */
    public function effectiveStatus(): PlatformAnnouncementStatus
    {
        if ($this->status === PlatformAnnouncementStatus::Published
            && $this->expires_at !== null && $this->expires_at->isPast()) {
            return PlatformAnnouncementStatus::Expired;
        }

        return $this->status;
    }
}
