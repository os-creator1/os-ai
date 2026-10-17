<?php

namespace App\Library\Dashboard\Home;

use App\Library\Analytics\AnalyticsDateRange;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The period a Home card links to, read back by the list it opens.
 *
 * Home's cards count over a Business-local date range (AnalyticsDateRange: local days, converted to the storage-timezone bounds
 * every reader uses). The list a card opens must show exactly those records, so it reads the same two local dates
 * (`created_from`, `created_to`) and builds the SAME range object — never its own date maths. Anything that is not a valid range
 * (malformed, reversed, too long) is ignored: the list is simply unfiltered, never an error and never a wider set of records.
 */
final class HomeDestinationRange
{
    public static function fromRequest(Request $request, Business $business): ?AnalyticsDateRange
    {
        $from = $request->input('created_from');
        $to = $request->input('created_to');

        if (! is_string($from) || ! is_string($to) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }

        try {
            $range = AnalyticsDateRange::fromInput(
                ['range' => AnalyticsDateRange::PRESET_CUSTOM, 'start' => $from, 'end' => $to],
                (string) ($business->timezone ?: config('app.timezone', 'UTC')),
            );
        } catch (ValidationException) {
            return null;
        }

        // A rejected range can fall back to a preset: only the range that was actually asked for filters anything.
        return $range->preset === AnalyticsDateRange::PRESET_CUSTOM ? $range : null;
    }
}
