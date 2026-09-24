<?php

namespace App\Enums\AgencyBilling;

/**
 * Lane C §C4.1 — the claim/lease state of one durable lane-C webhook event.
 *
 * Mirrors lane A's event-state vocabulary because the consumer shape is the
 * same one; it is a separate enum because a lane-C event and a lane-A event
 * must never be interchangeable, and a shared type would make them look it.
 */
enum AgencySubscriptionEventState: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
