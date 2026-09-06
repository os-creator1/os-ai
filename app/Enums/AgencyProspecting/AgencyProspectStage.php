<?php

namespace App\Enums\AgencyProspecting;

/**
 * A typed, validated foundation for the later AI responder engine's
 * conversation-stage tracking, per a prospect's enrollment in one
 * campaign (App\Models\AgencyProspectCampaignMember::$stage). No
 * automatic transition between these values exists in this foundation
 * pass — a repository-wide audit (both the current tree and full git
 * history) found no real, connected automatic AI responder/state-machine
 * implementation to preserve or port; only these exact seven values are
 * defined, matching the foundation task's own specification, and no
 * additional stage is invented.
 */
enum AgencyProspectStage: int
{
    case Introduction = 1;
    case Qualification = 2;
    case CallInvitation = 3;
    case BookingLinkSent = 4;
    case SchedulingConfirmation = 5;
    case Booked = 6;
    case StoppedOptOut = 99;
}
