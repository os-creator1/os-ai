<?php

namespace App\Library\Messaging\DTO;

/**
 * Slice 3 §4.3 — one outbound send, already resolved.
 *
 * Both the identity and the specific number are resolved server-side from
 * the tenancy-verified Business before this object is constructed; no
 * request input ever reaches these fields (T-MSG-12).
 */
final readonly class OutboundMessageRequest
{
    /**
     * @param list<string> $mediaUrls
     */
    public function __construct(
        public int $businessMessagingIdentityId,
        public int $businessMessagingNumberId,
        public string $messagingProfileId,
        public string $fromNumber,
        public string $toNumber,
        public string $body,
        public array $mediaUrls,
        public string $operationKey,
    ) {
    }
}
