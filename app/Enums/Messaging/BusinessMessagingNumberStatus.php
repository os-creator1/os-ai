<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.2/§4.3 — the lifecycle of one managed phone number.
 *
 * Released exists as a queryable end-state; the workflow that transitions a
 * number into it is Slice 4's, not Slice 3's (§4.1).
 */
enum BusinessMessagingNumberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Released = 'released';
}
