<?php

namespace App\Library\Coo;

use App\Enums\Coo\SignalDirection;

/**
 * Unified Business Home and COO Decision Engine contract §6.3 — S2, pure.
 *
 * No database, no HTTP, no AI, and no injected dependency: `compare()`
 * reads exactly two values, both from `config('coo.materiality.*')`
 * (`config/coo.php`), and returns a classification from two integers alone.
 * Given the same two config values, the same pair of counts always
 * classifies the same way.
 *
 * The two thresholds must BOTH hold for a change to be material:
 *
 *   max(current, previous) >= min_volume
 *   AND
 *   abs(current - previous) / max(previous, 1) >= min_relative_change
 *
 * `max(previous, 1)` avoids division by zero when the previous period had
 * no occurrences at all, without ever discarding a real, volume-qualifying
 * change (contract §6.3).
 *
 * A classification is a fact about direction and materiality, never a value
 * judgment: this class has no notion of "good" or "bad", and callers must
 * not invent one either — more inbound messages may mean more demand or
 * more unresolved workload.
 */
final class SignalComparator
{
    public static function compare(int $current, int $previous): SignalDirection
    {
        $minVolume = (int) config('coo.materiality.min_volume', 10);
        $minRelativeChange = (float) config('coo.materiality.min_relative_change', 0.30);

        $volume = max($current, $previous);

        if ($volume < $minVolume) {
            return SignalDirection::InsufficientData;
        }

        $relativeChange = abs($current - $previous) / max($previous, 1);

        if ($relativeChange < $minRelativeChange) {
            return SignalDirection::Stable;
        }

        return $current > $previous ? SignalDirection::MaterialIncrease : SignalDirection::MaterialDecrease;
    }
}
