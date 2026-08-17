<?php

namespace App\Library\Usage;

/**
 * M3 contract §8/§13 — the gateway's normalized return shape for
 * verifyWebhookSignature(). metadata is consumed only as an untrusted
 * routing hint (M3 contract §13 step 9) — never authoritative by itself.
 */
final readonly class WebhookVerificationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public string $providerObjectId,
        public string $rawPayload,
        public array $metadata,
        public ?int $amountMinorUnits,
        public ?string $currencyCode,
        public ?string $providerCustomerId,
    ) {
    }
}
