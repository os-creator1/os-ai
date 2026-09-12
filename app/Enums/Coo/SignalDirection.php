<?php

namespace App\Enums\Coo;

/**
 * Unified Business Home and COO Decision Engine contract §6.3 — the exact
 * classification `SignalComparator` returns for one metric pair.
 *
 * Factual, not evaluative: an increase is not "good" and a decrease is not
 * "bad" (more inbound messages may mean more demand or more unresolved
 * workload). Callers attach meaning; this enum never does.
 */
enum SignalDirection: string
{
    case MaterialIncrease = 'material_increase';
    case MaterialDecrease = 'material_decrease';
    case Stable = 'stable';
    case InsufficientData = 'insufficient_data';
}
