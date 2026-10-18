<?php

namespace App\Library\PlatformOwner\Announcements;

use App\Models\PlatformAnnouncement;

/**
 * The seam between the announcement LIFECYCLE (draft, schedule, publish, cancel: the one canonical
 * manager) and announcement DELIVERY (who actually receives what, over which channel, and when).
 *
 * The canonical manager owns the status transition and calls this port exactly once on publish
 * and once on cancel-after-publish. An implementation must be idempotent per announcement uid.
 * It returns an opaque delivery reference or null; delivery state of its own (recipients,
 * per-channel receipts) belongs to the implementation — management never duplicates it.
 *
 * The container binds it to AppLibraryPlatformAutomationAnnouncements * CanonicalPlatformAnnouncementDelivery (the Platform Automations delivery runtime); tests may
 * rebind it to observe or fake delivery.
 */
interface PlatformAnnouncementDelivery
{
    public function deliver(PlatformAnnouncement $announcement): ?string;

    public function withdraw(PlatformAnnouncement $announcement): void;
}
