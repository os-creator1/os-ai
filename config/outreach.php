<?php

return [
    /*
    | Randomised, human-paced delays before an automatic Outreach reply (per-Agency windows live
    | in Script & Settings). STOP / opt-out is never delayed. Turn off only for tests and local
    | debugging; phpunit.xml does so that the immediate-reply suites keep their assumptions.
    */
    'reply_delay_enabled' => (bool) env('OUTREACH_REPLY_DELAY_ENABLED', true),

    /*
    | Shared outbound pacing per SENDING NUMBER (every Outreach campaign on the same number shares it).
    | Manual and other managed sends from the same Business count against the budget but are never
    | blocked by it. A send over budget is deferred (a delayed job), never slept on and never lost.
    |
    | provider_min_interval_seconds is a FLOOR per transport ('managed', 'byo_telnyx'): set it from the
    | provider's verified limit. It is empty by default because no limit is assumed here; the effective
    | spacing is always the strictest of the default, the per-sender override, the provider floor and
    | the campaign's own min_send_interval_seconds.
    */
    'send_pacing' => [
        'enabled' => (bool) env('OUTREACH_SEND_PACING_ENABLED', true),
        'interval_seconds' => (int) env('OUTREACH_SEND_INTERVAL_SECONDS', 10),
        'sender_interval_seconds' => [],            // sending number (digits) => seconds
        'provider_min_interval_seconds' => [],      // 'managed' | 'byo_telnyx' => seconds
        'opener_daily_cap' => (int) env('OUTREACH_OPENER_DAILY_CAP', 50),   // new openers per 24h per number; 0 = unlimited
    ],
];
