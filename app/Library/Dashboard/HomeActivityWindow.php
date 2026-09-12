<?php

namespace App\Library\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Unified Business Home §2.3 (Slice H-2) — the window the Business activity
 * band counts over, and how it is framed.
 *
 * The frame adapts to how recently this customer was last here, so an hourly
 * visitor sees a useful day rather than a near-empty delta:
 *
 *  - MODE_TODAY — they were already here earlier today (Business-local
 *    calendar day): the window is the whole local day so far.
 *  - MODE_SINCE_LAST_VISIT — their previous visit was 1–6 days ago: the
 *    window starts exactly where that visit ended.
 *  - MODE_CATCH_UP — their previous visit was 7+ days ago: a BOUNDED window
 *    of the last N days, never an unbounded historical delta, and the label
 *    says so.
 *
 * Both instants are storage-timezone bounds for a half-open interval
 * [start, end), exactly like AnalyticsDateRange's, so the counts that use
 * them can never double-count a boundary.
 */
final class HomeActivityWindow
{
    public const MODE_TODAY = 'today';
    public const MODE_SINCE_LAST_VISIT = 'since_last_visit';
    public const MODE_CATCH_UP = 'catch_up';

    public function __construct(
        public readonly string $mode,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $timezone,
        /** The previous visit, in the Business's timezone, for the label. */
        public readonly ?CarbonImmutable $previousVisitLocal,
        public readonly int $catchUpDays,
    ) {
    }

    /** The short frame shown under the band heading. */
    public function label(): string
    {
        return match ($this->mode) {
            self::MODE_TODAY => 'Today so far',
            self::MODE_SINCE_LAST_VISIT => 'Since your last visit'
                . ($this->previousVisitLocal !== null ? ' (' . $this->previousVisitLocal->format('D j M') . ')' : ''),
            default => 'Last ' . $this->catchUpDays . ' days',
        };
    }

    /** The same frame inside a sentence, for the quiet nothing-changed state. */
    public function emptySentence(): string
    {
        return match ($this->mode) {
            self::MODE_TODAY => 'No new activity today so far.',
            self::MODE_SINCE_LAST_VISIT => 'No new activity since your last visit'
                . ($this->previousVisitLocal !== null ? ' on ' . $this->previousVisitLocal->format('D j M') : '') . '.',
            default => 'No new activity in the last ' . $this->catchUpDays . ' days.',
        };
    }
}
