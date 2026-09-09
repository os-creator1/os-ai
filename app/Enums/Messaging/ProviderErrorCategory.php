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
    case Unknown = 'unknown';
}
