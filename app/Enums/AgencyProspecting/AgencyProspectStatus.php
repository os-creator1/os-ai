<?php

namespace App\Enums\AgencyProspecting;

/**
 * A prospect's single, Workspace-scoped suppression flag. `Stopped` and
 * `Booked` are set only by an explicit, human-initiated action in this
 * foundation pass (no automatic opt-out detection or booking-linkage
 * engine exists yet) — see AgencyProspectingController::stopProspect()/
 * markProspectBooked().
 */
enum AgencyProspectStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Booked = 'booked';
}
