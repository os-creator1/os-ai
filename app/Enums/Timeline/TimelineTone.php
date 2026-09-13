<?php

namespace App\Enums\Timeline;

/**
 * How an activity card reads at a glance. Warning is for something the Business
 * should notice — an opt-out, an automation that could not send — never for an
 * ordinary event.
 */
enum TimelineTone: string
{
    case Neutral = 'neutral';
    case Success = 'success';
    case Warning = 'warning';
}
