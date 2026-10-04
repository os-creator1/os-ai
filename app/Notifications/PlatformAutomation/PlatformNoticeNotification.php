<?php

namespace App\Notifications\PlatformAutomation;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A Platform-originated in-app notice (an automation step or an announcement).
 * Stored in platform_database_notifications, the substrate the bell already reads for
 * Payments & Contracts activity; surfaced by PlatformNoticeReader.
 */
class PlatformNoticeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly string $severity = 'info',
        public readonly ?int $announcementId = null,
        public readonly ?int $runId = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'platform_notice',
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'announcement_id' => $this->announcementId,
            'run_id' => $this->runId,
        ];
    }
}
