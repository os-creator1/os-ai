<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Awaiting reply (Unified Business Home §2.6, H-4)
    |--------------------------------------------------------------------------
    |
    | "Awaiting reply" is a CURRENT operational count, never a period KPI: how
    | many conversations are waiting for this Business right now.
    |
    | The grace period exists so the Home does not tell an owner they are late
    | the instant a message arrives. The scan horizon exists so a conversation
    | abandoned months ago is not reported as waiting forever — it is not a
    | thing anyone is about to answer, and counting it would make the figure
    | useless.
    |
    */

    // A conversation whose last message is the customer's counts as waiting
    // only once it is at least this old.
    'awaiting_reply_grace_minutes' => env('CONVERSATIONS_AWAITING_REPLY_GRACE_MINUTES', 5),

    // Only conversations touched within this many days are scanned.
    'awaiting_reply_scan_days' => env('CONVERSATIONS_AWAITING_REPLY_SCAN_DAYS', 30),

];
