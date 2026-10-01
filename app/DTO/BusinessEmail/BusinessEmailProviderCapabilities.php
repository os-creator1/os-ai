<?php

namespace App\DTO\BusinessEmail;

/**
 * Explicit, not inferred: what a provider adapter can do TODAY. Callers
 * consult this instead of branching on provider type.
 */
final class BusinessEmailProviderCapabilities
{
    public function __construct(
        public readonly bool $plainTextSend = true,
        public readonly bool $htmlSend = false,
        public readonly bool $attachments = false,
        public readonly bool $inboundSync = false,
        public readonly bool $returnsProviderMessageId = true,
        public readonly bool $revocableGrant = false,
    ) {
    }
}
