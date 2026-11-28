<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.3 — normalized provider failure classes, so a caller never
 * parses a provider-specific error string.
 */
enum ProviderErrorCategory: string
{
    case Retryable = 'retryable';
    case Terminal = 'terminal';
    case Configuration = 'configuration';
    /** HTTP 403 that is NOT an authentication failure: the key was accepted, but the provider refuses this account, number or profile (permission, registration, restriction). */
    case Restricted = 'restricted';
    case Unknown = 'unknown';
}
