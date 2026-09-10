<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.3 — the adapter's own verdict on one send attempt.
 *
 * Accepted means the provider itself confirmed acceptance; a timeout or any
 * ambiguous response is Rejected, never Accepted (§4.9, T-MSG-42).
 */
enum MessageDispatchStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
