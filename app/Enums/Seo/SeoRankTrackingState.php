<?php

namespace App\Enums\Seo;

/** Whether a target consumes a paid slot. Stopped targets keep history and make no provider calls. */
enum SeoRankTrackingState: string
{
    case Tracking = 'tracking';
    case Stopped = 'stopped';
}
