<?php

namespace App\Events\Conversation;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A managed outbound operation's bubble moved on (delivered / delivery failed). Ids only, after commit; the listener
 * re-reads everything (same pattern as InboundMessageReceived).
 */
class OutboundDeliveryStateChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $operationId)
    {
    }
}
