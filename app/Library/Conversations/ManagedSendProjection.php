<?php

namespace App\Library\Conversations;

/**
 * What a durable business_messaging_operations row authorizes a tracked
 * chat_box_messages bubble to show — ManagedSendStateMachine::projectOperation()'s
 * one return shape, reused everywhere that mapping is needed instead of each
 * caller re-deriving it.
 */
final readonly class ManagedSendProjection
{
    public function __construct(
        public string $sendStatus,
        public ?string $failureReason,
    ) {
    }
}
