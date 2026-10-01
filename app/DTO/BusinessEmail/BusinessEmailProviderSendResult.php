<?php

namespace App\DTO\BusinessEmail;

/** The provider's own identifiers for an ACCEPTED send request. */
final class BusinessEmailProviderSendResult
{
    public function __construct(
        public readonly ?string $providerMessageId,
        public readonly ?string $providerThreadId,
        public readonly ?string $internetMessageId,
    ) {
    }
}
