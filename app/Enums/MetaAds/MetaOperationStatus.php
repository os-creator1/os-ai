<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §3 — closed set, mirrors the Google ledger. `Unknown` is
 * written when a provider call times out or the connection drops after the
 * request was sent; it is never replayed. `Deferred` is a throttle or an
 * exhausted local budget, not a failure.
 */
enum MetaOperationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Deferred = 'deferred';
}
