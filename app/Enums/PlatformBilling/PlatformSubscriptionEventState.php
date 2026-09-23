<?php

namespace App\Enums\PlatformBilling;

/**
 * Implementation Contract 21 §12 — the lane-A webhook row's own state machine.
 *
 * `Ignored` and `Failed` are different on purpose. Ignored means "understood
 * and correctly not acted on" (an event type outside the closed set, or a
 * replay of something already applied). Failed means "we could not place this
 * event safely", which is the fail-closed outcome §12.4 requires for an event
 * whose customer or subscription does not resolve to a lane-A record — never
 * a guess, and never a write into another lane's rows.
 */
enum PlatformSubscriptionEventState: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
