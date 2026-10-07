<?php

namespace App\Library\PlatformAutomation\Announcements;

use App\Jobs\PlatformAutomation\DeliverPlatformAnnouncementChunk;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementDelivery;
use App\Models\PlatformAnnouncement;

/**
 * THE delivery runtime behind PlatformAnnouncementDelivery: the audience is resolved once into
 * customer user ids and handed to queued, chunked, idempotent DeliverPlatformAnnouncementChunk
 * jobs (banner, notification and email channels). It never changes an announcement's status:
 * the manager's single claim already did, and a chunk re-checks it before delivering, so a
 * cancel between publish and a chunk delivers nothing further.
 */
final class CanonicalPlatformAnnouncementDelivery implements PlatformAnnouncementDelivery
{
    public function deliver(PlatformAnnouncement $announcement): ?string
    {
        $total = 0;

        PlatformAnnouncementAudience::query((array) $announcement->audience)
            ->orderBy('users.id')
            ->chunk(PlatformAnnouncementManager::CHUNK, function ($rows) use ($announcement, &$total) {
                $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
                $total += count($ids);
                DeliverPlatformAnnouncementChunk::dispatch($announcement->id, $ids);
            });

        $announcement->forceFill(['recipients_total' => $total])->save();

        return $announcement->uid;
    }

    /**
     * Nothing to undo: banners are read through the announcement's status and expiry on every
     * feed request, so a cancelled announcement is gone at once; notices and emails already
     * delivered are never recalled.
     */
    public function withdraw(PlatformAnnouncement $announcement): void
    {
    }
}
