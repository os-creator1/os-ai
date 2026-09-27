<?php

namespace App\Enums\Messaging;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4: "A customer may
 * port a number out. The platform must not obstruct it. Porting out is a
 * supported, documented request path, not a support escalation."
 *
 * This slice records and tracks the request only — it never performs the
 * actual port, release, replace, transfer or purchase of a number, and
 * never calls Telnyx. There is deliberately no `Completed` case here: the
 * eventual release, once a real port completes (through the customer's
 * winning carrier and Telnyx's own porting process — never automated by
 * this platform), belongs to a later release slice, which adds its own
 * case via its own additive migration when authorized, exactly as §8 of
 * the architecture decision requires for every future extension of this
 * schema family.
 */
enum PortOutRequestStatus: string
{
    case Requested = 'requested';
    case Cancelled = 'cancelled';
}
