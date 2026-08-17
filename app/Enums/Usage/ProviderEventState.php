<?php

namespace App\Enums\Usage;

/**
 * payment_provider_events.state (RFC-005 §21, M3 contract §21.5/§14).
 * Disposed is the terminal dead-letter state — a disposed event never
 * matches the claim UPDATE's WHERE clause again (M3 contract §14).
 */
enum ProviderEventState: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case Disposed = 'disposed';
}
