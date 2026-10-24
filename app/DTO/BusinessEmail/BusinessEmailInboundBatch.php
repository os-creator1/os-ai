<?php

namespace App\DTO\BusinessEmail;

/** A bounded page of inbound messages plus the cursor to persist once they are stored. */
final class BusinessEmailInboundBatch
{
    /** @param list<BusinessEmailInboundMessage> $messages */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $cursor,
        public readonly bool $hasMore = false,
        /** @var list<string> */
        public readonly array $unparsed = [],
    ) {
    }
}
