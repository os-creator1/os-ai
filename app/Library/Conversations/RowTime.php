<?php

namespace App\Library\Conversations;

use Carbon\CarbonInterface;

/**
 * The short time label of a conversation row, as in the approved Conversations design:
 * "2:57 PM" today, "Oct 10" earlier this year, "Oct 10, 2025" before that — in the viewer's timezone.
 */
final class RowTime
{
    public static function format(?CarbonInterface $at, ?string $timezone = null): string
    {
        if ($at === null) {
            return '';
        }

        $timezone = $timezone ?: (auth()->user()?->timezone ?: config('app.timezone'));
        $local = $at->copy()->setTimezone($timezone);
        $now = now($timezone);

        return match (true) {
            $local->isSameDay($now) => $local->format('g:i A'),
            $local->year === $now->year => $local->format('M j'),
            default => $local->format('M j, Y'),
        };
    }
}
