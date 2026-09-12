<?php

namespace App\DTO\Coo;

use App\Enums\Coo\SignalDirection;
use App\Library\Coo\SignalComparator;

/**
 * Unified Business Home and COO Decision Engine contract §6.2/§6.3 — one
 * metric's raw current and previous counts. Carries no judgment of its own;
 * `direction()` delegates to the pure `SignalComparator`.
 */
final class SignalMetric
{
    public function __construct(
        public readonly int $current,
        public readonly int $previous,
    ) {
    }

    public function direction(): SignalDirection
    {
        return SignalComparator::compare($this->current, $this->previous);
    }
}
