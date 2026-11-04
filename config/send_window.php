<?php

/*
 * When automated outbound messages may go out, in the BUSINESS's own timezone.
 *
 * Quiet hours always apply to texts and e-mail the engine sends (a compliance
 * default, not a preference): nothing automated is sent between `quiet_start`
 * and `quiet_end` local time. Business hours apply only where a workflow asks
 * for them AND the Location has recorded hours; a Location with no hours is
 * never treated as closed (an unknown is not a "no").
 */
return [
    'quiet_start' => env('SEND_WINDOW_QUIET_START', '21:00'),
    'quiet_end' => env('SEND_WINDOW_QUIET_END', '08:00'),
    'search_days' => 9,
];
