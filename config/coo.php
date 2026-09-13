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

    /*
     * Contract §8.1 — a Business with no new contact, conversation, incoming
     * message, automation run or member visit in this many days is dormant,
     * and no scheduled COO AI is spent on it. AI-1's AiBusinessActivityGate
     * already reads this key; it is declared here so it has one home.
     */
    'dormant_after_days' => (int) env('COO_DORMANT_AFTER_DAYS', 30),

    /*
     * Slice AI-3 — the cached "What we notice" insight (contract §8, §9).
     */
    'insight_ttl_days' => (int) env('COO_INSIGHT_TTL_DAYS', 7),

    'insight' => [

        /*
         * Bumping either makes every older insight unselectable (§9.3). The
         * prompt version names the instructions sent; the policy version names
         * the facts, buckets and eligibility rules that produced them.
         */
        'prompt_version' => (int) env('COO_INSIGHT_PROMPT_VERSION', 1),
        'policy_version' => (int) env('COO_INSIGHT_POLICY_VERSION', 1),

        /*
         * §9.1 — the fixed count bands, as the lower bound of each: 0, 1–4,
         * 5–9, 10–24, 25–49, 50–99, 100+. Counts are bucketed before they are
         * fingerprinted, so a one-contact change never buys a new insight.
         */
        'count_buckets' => [0, 1, 5, 10, 25, 50, 100],

        /* §8.2 E-4 — one explicit "Explain this change" per subject in this window. */
        'explain_window_hours' => (int) env('COO_INSIGHT_EXPLAIN_WINDOW_HOURS', 24),

        /* The most the model may write; the route's own ceiling still applies. */
        'max_output_tokens' => (int) env('COO_INSIGHT_MAX_OUTPUT_TOKENS', 450),

        /* §8.2 E-3 — reasoning is requested only with at least this many material signals. */
        'reasoning_min_material_signals' => (int) env('COO_INSIGHT_REASONING_MIN_MATERIAL_SIGNALS', 3),

        'queue' => env('COO_INSIGHT_QUEUE', 'default'),

    ],

];
