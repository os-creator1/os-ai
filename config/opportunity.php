<?php

return [
    'enabled' => env('OPPORTUNITY_ENGINE_ENABLED', false),
    'queue' => env('OPPORTUNITY_ENGINE_QUEUE', 'default'),
    'run_timeout_minutes' => env('OPPORTUNITY_RUN_TIMEOUT_MINUTES', 30),
    'max_candidates_per_run' => env('OPPORTUNITY_MAX_CANDIDATES_PER_RUN', 100),
    'snooze_sweep_minutes' => env('OPPORTUNITY_SNOOZE_SWEEP_MINUTES', 15),

    // Semantic versions of trusted, source-controlled algorithms — not meant
    // to be changed via .env; changing either is a code change (a new
    // canonicalization or scoring formula version), documented in RFC-002.
    'fingerprint_version' => 1,
    'scoring_version' => 1,
];
