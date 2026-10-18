<?php

namespace App\Library\PlatformAutomation;

use App\Models\PlatformDatabaseNotification;
use App\Models\User;
use App\Notifications\PlatformAutomation\PlatformNoticeNotification;
use Illuminate\Support\Collection;

/** Unread Platform notices for one user: exactly their own rows, newest first. */
class PlatformNoticeReader
{
    private const LIMIT = 20;

    /** @return Collection<int, PlatformDatabaseNotification> */
    public function unreadFor(User $user): Collection
    {
        return PlatformDatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('type', PlatformNoticeNotification::class)
            ->whereNull('read_at')
            ->latest()
            ->limit(self::LIMIT)
            ->get();
    }

    public function markRead(User $user, string $notificationId): bool
    {
        $row = PlatformDatabaseNotification::query()
            ->where('id', $notificationId)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('type', PlatformNoticeNotification::class)
            ->first();

        if ($row === null) {
            return false;
        }

        $row->markAsRead();

        return true;
    }
}
