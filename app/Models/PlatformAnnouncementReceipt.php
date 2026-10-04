<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-user, per-channel delivery of an announcement; its unique key is the delivery idempotency ledger. */
class PlatformAnnouncementReceipt extends Model
{
    protected $table = 'platform_announcement_receipts';

    protected $fillable = ['announcement_id', 'user_id', 'channel', 'delivered_at', 'dismissed_at'];

    protected $casts = [
        'delivered_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(PlatformAnnouncement::class, 'announcement_id');
    }
}
