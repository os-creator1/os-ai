<?php

namespace App\Library\PlatformOwner\Announcements;

use App\Models\PlatformAnnouncement;

/** Default delivery binding: nothing is sent. See PlatformAnnouncementDelivery. */
class NullPlatformAnnouncementDelivery implements PlatformAnnouncementDelivery
{
    public function deliver(PlatformAnnouncement $announcement): ?string
    {
        return null;
    }

    public function withdraw(PlatformAnnouncement $announcement): void
    {
    }
}
