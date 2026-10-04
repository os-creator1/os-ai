<?php

namespace App\Library\PlatformOwner\Announcements;

use App\Models\PlatformAnnouncement;

/**
 * The seam between announcement MANAGEMENT (this lane: draft, schedule,
 * publish, cancel, audience) and announcement DELIVERY (the Platform
 * Automations lane: who actually receives what, over which channel, and when).
 *
 * The manager owns the lifecycle state and calls this port exactly once on
 * publish and once on cancel-after-publish. An implementation must be
 * idempotent per announcement uid. It returns an opaque delivery reference
 * (stored in platform_announcements.delivery_ref) or null. Delivery state of
 * its own (recipients, per-channel outcome) belongs to the implementation —
 * management never duplicates it.
 *
 * The default binding is NullPlatformAnnouncementDelivery (records nothing
 * and reaches nobody) until Platform Automations binds its own.
 */
interface PlatformAnnouncementDelivery
{
    public function deliver(PlatformAnnouncement $announcement): ?string;

    public function withdraw(PlatformAnnouncement $announcement): void;
}
