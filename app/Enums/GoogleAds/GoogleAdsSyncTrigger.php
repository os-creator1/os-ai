<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §4 — what started a sync run.
 */
enum GoogleAdsSyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Connect = 'connect';
}
