<?php

namespace App\Library\MetaAds\Mutations;

/**
 * Meta Ads Module V1 contract 24 §7 — the closed set of results a pause /
 * resume request can have. Only `Succeeded` means Meta applied the change.
 */
enum MetaAdsMutationOutcomeStatus: string
{
    /** Meta applied it; local state already reflects it. */
    case Succeeded = 'succeeded';

    /** Sent, but the answer was lost: ledger `unknown`, never replayed. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Definitely not applied (rejected / token dead); local state untouched. */
    case Failed = 'failed';

    /** Rate limited or over our own hourly budget: not a failure, not retried. */
    case Deferred = 'deferred';

    /** Nothing was sent: already in that state, or a change is already in flight. */
    case DuplicateNoop = 'duplicate_noop';
}
