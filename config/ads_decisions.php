<?php

/*
|--------------------------------------------------------------------------
| Acquisition Purpose + Ads Decisioning V1 — the decision policy
|--------------------------------------------------------------------------
|
| Every number the deterministic Ads decision engine (AdsDecisionEngine)
| compares against lives HERE and nowhere else, so each is documented,
| tested (AdsDecisionPolicyTest pins the defaults) and changeable without
| touching rule code. None of them is a money amount: money always comes from
| the Business's own answers (the Acquisition Purpose economics). They are
| MULTIPLES of those answers and evidence counts.
|
| There are deliberately no "wait N days" values. How long to wait is a
| function of evidence — spend relative to the target cost of a result, and the
| number of qualified outcomes — never of the calendar.
*/

return [

    'decision' => [
        // Spend with ZERO qualified results, as a multiple of the target cost
        // of one qualified lead.
        //   below `watch_from`  -> WAIT   (too early for the result to be missing)
        //   [watch_from, act_at) -> WATCH
        //   at/above `act_at`   -> ACT    (only with healthy tracking)
        'zero_result_watch_from_multiple' => 1.0,
        'zero_result_act_at_multiple' => 2.5,

        // Fewest qualified leads before a cost-per-qualified-lead verdict
        // (good OR bad) is allowed to change what the owner does. Below it the
        // sample is too small to judge.
        'min_qualified_for_cost_judgement' => 10,

        // Cost per qualified lead above the target but within this multiple of
        // the target (when no hard maximum is set) is WATCH, beyond it ACT.
        'over_target_tolerance_multiple' => 1.25,

        // Fewest qualified leads before "few of them become customers" is
        // allowed to be called a funnel problem (and "don't touch the ads").
        'min_qualified_for_funnel_judgement' => 10,

        // Enrolment evidence needs this many finished outcomes before a cost
        // per outcome is trusted over the cost per lead.
        'min_outcomes_for_cac' => 2,

        // The actual qualified->outcome rate below this share of the owner's
        // expected rate is a funnel problem.
        'weak_conversion_share_of_expected' => 0.5,

        // Clicks needed before a total absence of recorded inquiries is read
        // as a tracking problem rather than a quiet week.
        'min_clicks_for_tracking_check' => 30,

        // Click-through rate below this (with enough impressions) hints the
        // creative/hook is weak; clicks -> inquiries below this hints the
        // landing page/offer is weak. Diagnosis wording only: never a verdict.
        'weak_ctr' => 0.005,
        'min_impressions_for_ctr' => 2000,
        'weak_click_to_inquiry' => 0.01,

        // Re-check after this many MORE qualified leads or this multiple of the
        // target cost in further spend, whichever comes first.
        'review_after_more_qualified' => 4,
        'review_after_more_spend_multiple' => 1.0,

        // Google only: search-term waste worth acting on.
        'search_term_waste_share' => 0.25,
        'search_term_waste_min_target_multiple' => 1.0,
    ],

    // What counts as a QUALIFIED lead is fixed and documented in the contract:
    // an Opportunity in the purpose's pipeline, attributed to the provider, that
    // is won OR has left the pipeline's first (new_inquiry) stage. It is not
    // configurable per request on purpose: two screens must never disagree.
    'attribution' => [
        // The lookback used to ask "has this provider recorded ANY attributed
        // inquiry for this Business recently?" (the tracking-health probe).
        'tracking_lookback_days' => 30,
    ],

    'economics' => [
        // A suggestion, never a target: the share of one customer's lifetime
        // contribution proposed as a conservative cost to win them.
        'suggested_target_cac_fraction' => 0.3,
        'suggested_hard_cac_fraction' => 0.5,
    ],
];
