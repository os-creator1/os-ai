<?php

namespace App\Library\Dashboard;

use Carbon\CarbonImmutable;

/**
 * The one clock the visit marker reads, so a test can move time deliberately
 * without reaching into the marker's own rules.
 */
class HomeVisitClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
