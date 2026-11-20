<?php

return [
    /*
    | Randomised, human-paced delays before an automatic Outreach reply (per-Agency windows live
    | in Script & Settings). STOP / opt-out is never delayed. Turn off only for tests and local
    | debugging; phpunit.xml does so that the immediate-reply suites keep their assumptions.
    */
    'reply_delay_enabled' => (bool) env('OUTREACH_REPLY_DELAY_ENABLED', true),
];
