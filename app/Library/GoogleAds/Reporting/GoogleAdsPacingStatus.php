<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Contract §9 — spend pace against the Business MONTHLY target.
 * `ahead` = spending faster than the month is elapsing; `behind` = slower.
 */
enum GoogleAdsPacingStatus: string
{
    case OnPace = 'on_pace';
    case Ahead = 'ahead';
    case Behind = 'behind';
    case NoTarget = 'no_target';
    case InsufficientData = 'insufficient_data';
}
