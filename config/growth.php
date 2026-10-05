<?php

/*
|--------------------------------------------------------------------------
| Growth Center — deterministic rule thresholds and score settings
|--------------------------------------------------------------------------
|
| Every number a Growth rule compares against lives here and is read ONLY
| through App\Library\Growth\GrowthThresholds (which clamps each value to a
| sane range), so no rule carries a magic number of its own. Changing a
| threshold changes what a rule detects but never what it MEANS; a change in
| meaning is a new rule version (`crm.unanswered_new_leads:v2`), not an edit.
|
| These are defaults for every Business. There is no per-Business override
| in V1 (deferred — see GROWTH-CENTER-OPPORTUNITY-ENGINE-V1.md §Deferred).
|
| The Growth Center has no master switch of its own: it is part of the
| Opportunity Engine and honours `opportunity.enabled` exactly like the
| Business Advisor does.
*/

return [
    'thresholds' => [
        // CRM lead response
        'unanswered_lead_hours' => 24,          // new deal, status no_contact, older than this
        'stale_deal_days' => 7,                 // open deal with no activity for this long
        'high_value_deal_minor' => 50000,       // >= this (minor units) is a "high-value" deal
        'conversation_awaiting_hours' => 24,    // last message inbound, no reply for this long
        'conversation_lookback_days' => 30,     // only conversations active inside this window

        // Booking
        'low_availability_open_minutes' => 240, // open bookable minutes in the next 7 days

        // Documents / payments
        'proposal_unsigned_days' => 3,          // sent, still unsigned
        'signed_unpaid_days' => 3,              // signed, schedule item still pending
        'failed_payment_lookback_days' => 14,   // a failed attempt this recent, item still unpaid

        // Reviews / citations
        'review_request_lookback_days' => 30,   // no request recorded inside this window
        'citation_priority_directories' => 5,   // the first N active directories are "important"

        // SEO rank (stored observations only): a tracked keyword is a "meaningful
        // drop" when its organic position got worse by at least this many places
        // (or it fell out of the results entirely).
        'rank_drop_positions' => 5,

        // Dismiss cooldown: a dismissed Opportunity may return after this
        // many days, but only if a later run re-confirms the problem.
        'dismiss_cooldown_days' => 30,

        // Minimum sample sizes (a conversion/trend rule below this is silent)
        'min_sample' => 8,

        // Automations: failed runs/steps in the last 7 days before a Business is told
        'automation_failure_min' => 3,
    ],

    'score' => [
        // Algorithm version stored on every growth_score_snapshots row. A
        // formula change bumps this; old rows are NEVER recomputed.
        'version' => 1,

        // A category is only scored once it has at least this many applicable
        // rules whose fact readers are available.
        'min_applicable_rules' => 1,

        // Daily snapshots are kept this many days (>= 13 months).
        'retention_days' => 430,
    ],

    // Business-Advisor-style evaluation trigger knobs.
    'evaluation' => [
        'debounce_minutes' => 15,
        'sweep_limit' => 500,
        'sweep_page' => 100,
    ],
];
