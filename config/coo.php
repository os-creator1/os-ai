<?php

/*
 * Unified Business Home and COO Decision Engine contract §6.3 (Slice C-3).
 *
 * The single canonical location for every materiality threshold the
 * deterministic COO pipeline uses. `SignalComparator` reads these two values
 * and nothing else; no threshold literal belongs in a class.
 */

return [

    'materiality' => [

        /*
         * The volume floor. A metric pair is never classified as a material
         * increase or decrease unless max(current, previous) meets this —
         * otherwise a change from 1 to 3 (a 200% relative change on almost
         * no data) would be reported as material.
         */
        'min_volume' => (int) env('COO_MATERIALITY_MIN_VOLUME', 10),

        /*
         * The relative-change floor, as a fraction (0.30 = 30%). Combined
         * with the volume floor above, both must hold for a change to be
         * material; contract §6.3.
         */
        'min_relative_change' => (float) env('COO_MATERIALITY_MIN_RELATIVE_CHANGE', 0.30),

    ],

];
