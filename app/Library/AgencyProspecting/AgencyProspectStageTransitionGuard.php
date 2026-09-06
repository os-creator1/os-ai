<?php

namespace App\Library\AgencyProspecting;

use App\Enums\AgencyProspecting\AgencyProspectStage;

/**
 * Runtime pass — the sole authority for which stage transitions the AI
 * responder may apply. Stage semantics (task-specified, unchanged from the
 * foundation): 1 initial, 2 engaged/qualification, 3 call invitation, 4
 * booking link sent, 5 scheduling/confirmation, 6 booked (never AI-set),
 * 99 stopped (terminal). No backward movement; 6 and 99 are terminal and
 * never appear as a "current stage" this guard is asked about (terminal
 * members are never handed to the AI responder at all).
 */
final class AgencyProspectStageTransitionGuard
{
    private const ALLOWED = [
        1 => [2, 3, 99],
        2 => [3, 4, 99],
        3 => [4, 5, 99],
        4 => [5, 99],
        5 => [5, 99],
    ];

    public static function isAllowed(AgencyProspectStage $current, int $next): bool
    {
        return in_array($next, self::ALLOWED[$current->value] ?? [], true);
    }
}
