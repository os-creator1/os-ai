<?php

namespace App\Enums\BusinessEmail;

/**
 * The outbound message state machine. The words are deliberately narrow:
 *
 *   queued      — a local row exists; NO provider call has been made.
 *   sending     — a worker holds the claim and the provider call is (or was)
 *                 in flight.
 *   accepted    — the provider's API returned success for the send request.
 *                 This is NOT "delivered": no delivery receipt, bounce or
 *                 read signal is tracked by this slice, and there is
 *                 deliberately no `delivered` case.
 *   failed      — the provider call did not succeed; failure_category says
 *                 why. Retryable categories may be attempted again (bounded).
 *   unconfirmed — a `sending` claim went stale (a crash mid-call). The
 *                 provider may or may not have accepted the message, so it
 *                 is NEVER blindly re-sent; an operator/human resolves it.
 */
enum BusinessEmailMessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Unconfirmed = 'unconfirmed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Unconfirmed], true);
    }
}
