<?php

namespace App\Library\GoogleAds\Mutations;

/**
 * Google Ads Module V1 contract §6 — the closed set of results a mutation
 * request can have. Only `Succeeded` means Google applied the change.
 */
enum MutationOutcomeStatus: string
{
    /** Google applied it; local state already reflects it. */
    case Succeeded = 'succeeded';

    /** Sent, but the answer was lost: ledger `unknown`, never replayed. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Definitely not applied (rejected / revoked); local state untouched. */
    case Failed = 'failed';

    /** Rate limited or over our own hourly budget: not a failure, not retried. */
    case Deferred = 'deferred';

    /** Nothing was sent: already in that state, already excluded, or already in flight. */
    case DuplicateNoop = 'duplicate_noop';
}
