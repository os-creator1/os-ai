<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business activity window (Unified Business Home §2.3, H-2)
    |--------------------------------------------------------------------------
    |
    | Home answers "what actually changed since this customer last used this
    | Business?" from a per-user, per-Business visit marker. These values
    | decide when one visit ends and the next begins, and how the window is
    | framed so an hourly visitor still sees a useful day rather than a
    | near-zero delta.
    |
    */

    // A Home view this many minutes after the last one starts a NEW visit.
    'visit_gap_minutes' => env('HOME_VISIT_GAP_MINUTES', 30),

    // Within one visit, the "last seen" stamp is rewritten at most this often.
    'visit_last_seen_write_seconds' => env('HOME_VISIT_LAST_SEEN_WRITE_SECONDS', 60),

    // A previous visit older than this many days becomes a bounded catch-up
    // window of the same length, rather than an unbounded historical delta.
    'activity_catch_up_days' => env('HOME_ACTIVITY_CATCH_UP_DAYS', 7),

    // At most this many factual items render in the activity line.
    'activity_max_items' => env('HOME_ACTIVITY_MAX_ITEMS', 5),

];
