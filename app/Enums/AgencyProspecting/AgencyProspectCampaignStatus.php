<?php

namespace App\Enums\AgencyProspecting;

/**
 * A plain organizational label for this foundation pass — no automatic
 * sending is triggered by any of these values (the Workspace-level
 * provider seam and the AI responder engine are both deferred).
 */
enum AgencyProspectCampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
}
