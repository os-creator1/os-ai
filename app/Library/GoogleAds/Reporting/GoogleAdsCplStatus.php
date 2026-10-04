<?php

namespace App\Library\GoogleAds\Reporting;

/** Contract §9 — current cost per conversion against `target_cpl_micros`. */
enum GoogleAdsCplStatus: string
{
    case Better = 'better';
    case OnTarget = 'on_target';
    case Worse = 'worse';
    case NoTarget = 'no_target';
}
