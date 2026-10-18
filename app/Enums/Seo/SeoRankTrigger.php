<?php

namespace App\Enums\Seo;

/** Why a run exists. Scheduled runs follow cadence; manual runs are owner-initiated and cooldown-limited. */
enum SeoRankTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
