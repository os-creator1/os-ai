<?php

/*
|--------------------------------------------------------------------------
| Payments & Contracts (Proposal / Contract / e-signature / Invoice)
|--------------------------------------------------------------------------
|
| Implementation Contract 17 §12.A. Money lane B only (Addendum §12).
|
| Sub-slice A creates this file so later sub-slices read their knobs from a
| single, already-present config rather than each adding their own. NOTHING in
| Sub-slice A reads any of these keys: no command, job, controller or link
| exists yet, and the feature is still `Planned`. Shaped like
| config/opportunity.php — every value env()-backed except the ones that are
| code-versioned constants.
*/

return [
    // Master flag the scheduled commands of Sub-slice F own (the command, not
    // the scheduler, owns the disabled no-op — SweepExpiredOpportunitySnoozes
    // precedent). Off by default.
    'enabled' => env('DOCUMENTS_ENABLED', false),

    'queue' => env('DOCUMENTS_QUEUE', 'default'),

    // §6.3 — access_token_expires_at is the document's own expires_at when one
    // is set (the document's expiry always wins: a link must never outlive the
    // document it opens), otherwise now() plus this many days. Read by
    // Sub-slice C.
    'link_ttl_days' => env('DOCUMENTS_LINK_TTL_DAYS', 30),

    // Sub-slice F — bounded batch sizes for the three scheduled commands
    // (documents:expire-due, documents:dispatch-due-reminders,
    // documents:reconcile-stale-payments). A bounded batch that never drains
    // to empty; the scheduler's cadence provides eventual coverage.
    'expire_sweep_limit' => env('DOCUMENTS_EXPIRE_SWEEP_LIMIT', 100),
    'reminder_sweep_limit' => env('DOCUMENTS_REMINDER_SWEEP_LIMIT', 100),
    'stale_payment_sweep_limit' => env('DOCUMENTS_STALE_PAYMENT_SWEEP_LIMIT', 100),

    // §7.5 — how long a payment attempt may sit in a non-terminal local status
    // before the reconciliation sweep asks the provider about it. The sweep
    // never invents a terminal state itself.
    'stale_payment_minutes' => env('DOCUMENTS_STALE_PAYMENT_MINUTES', 30),

    // §8.4 — days-before-due offsets at which payment reminders become
    // eligible. Deduplication is by durable markers on the owning rows, never
    // by this list.
    'reminder_offsets_days' => [3, 1],
];
