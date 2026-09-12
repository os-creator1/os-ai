<?php

namespace App\Library\Dashboard;

use App\Enums\Dashboard\HeadlineTrend;

/**
 * Customer Experience Slice 4 §4.4 — one headline's current-versus-previous
 * value, bounded and immutable.
 *
 *  absoluteDelta  current − previous, always
 *  percentDelta   one decimal place, and NULL whenever previous is 0 — never
 *                 infinity, NaN or a fabricated 100% (the same rule, and the
 *                 same precision, MessageKpis::acceptedRate() already uses)
 *  trend          from absoluteDelta, never from percentDelta
 */
final class HeadlineComparison
{
    public readonly int $absoluteDelta;

    public readonly ?float $percentDelta;

    public readonly HeadlineTrend $trend;

    public function __construct(
        public readonly int $current,
        public readonly int $previous,
    ) {
        $this->absoluteDelta = $current - $previous;
        $this->percentDelta = $previous !== 0 ? round($this->absoluteDelta / $previous * 100, 1) : null;
        $this->trend = HeadlineTrend::fromDelta($this->absoluteDelta);
    }

    /**
     * The plain comparison line shown beside the figure, e.g. "Up 12 (25.0%)
     * from 48 in the previous 30 days." A previous value of 0 is said in
     * words, never as a percentage.
     *
     * H-3: the window is no longer fixed, so the caller names the period it
     * compared against — "the previous 30 days", "the previous 10 days" —
     * and the sentence can never claim a length the figures do not cover.
     */
    public function sentence(string $previousNoun = 'the previous period'): string
    {
        $previous = number_format($this->previous);

        if ($this->current === 0 && $this->previous === 0) {
            return "No change: 0 in this period and in {$previousNoun}.";
        }

        if ($this->trend === HeadlineTrend::Unchanged) {
            return "The same as {$previousNoun} ({$previous}).";
        }

        $direction = $this->trend === HeadlineTrend::Up ? 'Up' : 'Down';
        $amount = number_format(abs($this->absoluteDelta));

        if ($this->percentDelta === null) {
            return "{$direction} from {$previous} in {$previousNoun}.";
        }

        return "{$direction} {$amount} (" . number_format(abs($this->percentDelta), 1) . "%) from {$previous} in {$previousNoun}.";
    }
}
