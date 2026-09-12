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

    // COO C-1 automatic triggering. 'enabled' above still governs both
    // automatic paths: these only shape them once the owner turns the engine
    // on, and none of them can start the engine.
    //
    // trigger_debounce_minutes: a burst of Advisor-relevant profile edits for
    //   one Business costs one producer run per window (contract §7.3).
    // sweep_stale_hours: how long since a Business's last SUCCESSFUL Advisor
    //   run before the daily sweep nudges it.
    // sweep_limit / sweep_page: the daily sweep's bounds — Businesses per
    //   invocation, and candidates read per keyset page.
    'trigger_debounce_minutes' => env('OPPORTUNITY_TRIGGER_DEBOUNCE_MINUTES', 15),
    'sweep_stale_hours' => env('OPPORTUNITY_SWEEP_STALE_HOURS', 24),
    'sweep_limit' => env('OPPORTUNITY_SWEEP_LIMIT', 500),
    'sweep_page' => env('OPPORTUNITY_SWEEP_PAGE', 100),
];
