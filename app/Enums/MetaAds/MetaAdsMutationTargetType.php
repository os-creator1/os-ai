<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §7 — what a pause/resume mutation targets.
 */
enum MetaAdsMutationTargetType: string
{
    case Campaign = 'campaign';
    case AdSet = 'ad_set';
    case Ad = 'ad';
}
