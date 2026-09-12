<?php

namespace App\Enums\Automation\Workflow;

/**
 * Automations V2 §5.2/§7.4 — how safe a node is to re-derive after a process
 * died mid-step.
 *
 * This is load-bearing rather than descriptive. When the recovery sweep finds an
 * enrollment whose cursor node has a `started` step run and whose process is
 * gone, what it may do depends entirely on this class:
 *
 *   - None / IdempotentDatabase: re-derive or re-apply; nothing can have
 *     happened twice that matters.
 *   - External: NEVER re-executed. The step is failed with an
 *     outcome-unknown reason, because a text message may already have left the
 *     building. That is B4 §5.1 rule 4's accepted trade-off, applied only where
 *     it is genuinely needed.
 */
enum NodeSideEffectClass: string
{
    /** Pure evaluation — no write, no call. Trigger, wait, if/else, end. */
    case None = 'none';

    /** Writes, but setting a value to its configured value is idempotent. */
    case IdempotentDatabase = 'idempotent_database';

    /** Leaves the platform: a provider call, an email, a notification. */
    case External = 'external';

    /** Whether an interrupted step of this class may be retried automatically. */
    public function isSafeToReExecute(): bool
    {
        return $this !== self::External;
    }
}
