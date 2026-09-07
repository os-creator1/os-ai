<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §11.3.3 — closed set.
 *
 * `Unknown` is written when a provider call times out or the connection
 * drops after the request was sent (contract §24.6); it is never blindly
 * replayed and is retained permanently (§13.6). `Deferred` is written on
 * HTTP 429 / RESOURCE_EXHAUSTED — a rate-limited call is NOT a failure
 * (§24.5).
 */
enum GoogleOperationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Deferred = 'deferred';
}
