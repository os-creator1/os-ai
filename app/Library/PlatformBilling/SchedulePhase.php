<?php

namespace App\Library\PlatformBilling;

use Carbon\CarbonInterface;

/**
 * One phase of an exact, time-bounded Price sequence on a subscription.
 *
 * WHY PHASES AND NOT COUPONS. A temporary promotion has to last an EXACT number
 * of 28-day billing periods. Stripe's repeating coupon is measured in calendar
 * months, which is neither exact nor 28 days, so it is never used to
 * approximate this. Instead each promotional phase is a plain, immutable Price
 * with an explicit end instant, and the final phase has no end (the
 * subscription simply continues on it).
 *
 * `$endsAt` null means "open ended": the last phase.
 */
final readonly class SchedulePhase
{
    public function __construct(
        public string $priceId,
        public ?CarbonInterface $endsAt = null,
    ) {
    }
}
