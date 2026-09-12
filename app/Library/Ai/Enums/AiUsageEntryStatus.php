<?php

namespace App\Library\Ai\Enums;

/**
 * Contract §10.2 — deliberately NOT RFC-005's UsageReservationStatus. The
 * values look alike, but one is customer money and the other is platform
 * cost, and sharing the enum would couple the two domains.
 */
enum AiUsageEntryStatus: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
    case Failed = 'failed';
    case Refused = 'refused';
}
