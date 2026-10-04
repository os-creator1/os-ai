<?php

namespace App\Library\AgencyOutreach;

/**
 * Everything the send pipeline needs to claim, send and record ONE logical outbound
 * message. `operationKey` is both the ledger's unique key and the managed
 * dispatcher's idempotency key, so one logical message can never be sent twice.
 */
final class OutreachSendPlan
{
    public function __construct(
        public readonly string $operationKey,
        public readonly string $body,
        public readonly string $source,
        public readonly string $purpose,
        public readonly int $stageFrom,
        public readonly int $stageTo,
        public readonly ?string $intent = null,
        public readonly int $scriptVersion = 1,
        /** When true an owner's manual reply after the latest inbound suppresses this (replies only). */
        public readonly bool $suppressAfterManualReply = false,
        /** When true the member's AI-paused state blocks it (everything automatic). */
        public readonly bool $requireAiActive = true,
    ) {
    }
}
