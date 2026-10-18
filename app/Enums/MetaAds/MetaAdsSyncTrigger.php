<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §6 — what started a sync run.
 */
enum MetaAdsSyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Connect = 'connect';
}
