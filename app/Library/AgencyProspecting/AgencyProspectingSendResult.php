<?php

namespace App\Library\AgencyProspecting;

/**
 * Runtime pass — the structured outbound-send outcome every provider
 * adapter returns. Never carries credentials.
 */
final class AgencyProspectingSendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId,
        public readonly ?string $failureReason,
    ) {
    }

    public static function success(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null);
    }

    public static function failure(string $failureReason): self
    {
        return new self(false, null, $failureReason);
    }
}
