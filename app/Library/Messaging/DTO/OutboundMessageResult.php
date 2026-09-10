<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\MessageDispatchStatus;
use App\Enums\Messaging\ProviderErrorCategory;

/**
 * Slice 3 §4.3 — the adapter's verdict on one send.
 *
 * Deliberately carries no raw provider response body, so no credential or
 * customer payload can leak through it (T-MSG-41). A timeout or ambiguous
 * response is Rejected/Retryable, never Accepted (T-MSG-42).
 */
final readonly class OutboundMessageResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public MessageDispatchStatus $status,
        public ?ProviderErrorCategory $errorCategory = null,
    ) {
    }

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, MessageDispatchStatus::Accepted, null);
    }

    public static function rejected(ProviderErrorCategory $category, ?string $providerMessageId = null): self
    {
        return new self(false, $providerMessageId, MessageDispatchStatus::Rejected, $category);
    }
}
