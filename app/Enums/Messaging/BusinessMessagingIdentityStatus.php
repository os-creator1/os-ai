<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.2/§4.3 — the lifecycle of a managed Messaging-Profile mapping.
 *
 * Only Active resolves for outbound sending or inbound attribution; every
 * other status resolves to null, never to a partially usable object
 * (T-MSG-3). Pending and Active share the database-enforced
 * one-per-Business slot (§4.2's generated guard column); Suspended and
 * Archived free it.
 */
enum BusinessMessagingIdentityStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
