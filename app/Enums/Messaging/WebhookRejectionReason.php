<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.2/§4.6 — why one inbound webhook was refused.
 *
 * RegressiveTransition is distinct from Duplicate: a duplicate is an exact
 * replay of a status already applied, while a regressive transition asks
 * for a status absent from §4.6.3's permitted-next table.
 */
enum WebhookRejectionReason: string
{
    case InvalidSignature = 'invalid_signature';
    case MalformedPayload = 'malformed_payload';
    case Duplicate = 'duplicate';
    case UnknownMapping = 'unknown_mapping';
    case ConflictingMapping = 'conflicting_mapping';
    case RegressiveTransition = 'regressive_transition';
}
