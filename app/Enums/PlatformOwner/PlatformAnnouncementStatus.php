<?php

namespace App\Enums\PlatformOwner;

enum PlatformAnnouncementStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Cancelled = 'cancelled';
    /** Never stored: derived by PlatformAnnouncement::effectiveStatus(). */
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
