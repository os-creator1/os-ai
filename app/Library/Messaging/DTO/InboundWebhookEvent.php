<?php

namespace App\Library\Messaging\DTO;

use App\Enums\Messaging\InboundWebhookEventKind;
use Carbon\CarbonImmutable;

/**
 * Slice 3 §4.3/§4.6 — one verified inbound provider event.
 *
 * Both messagingProfileId and destinationNumber are explicitly named and
 * populated whenever the provider payload carries them, because §4.6
 * requires cross-checking the two independently; neither is ever sufficient
 * on its own.
 */
final readonly class InboundWebhookEvent
{
    /**
     * @param list<string> $mediaUrls
     */
    public function __construct(
        public InboundWebhookEventKind $kind,
        public ?string $messagingProfileId,
        public ?string $destinationNumber,
        public ?string $fromNumber,
        public ?string $body,
        public array $mediaUrls,
        public ?string $providerMessageId,
        public ?string $deliveryStatus,
        public CarbonImmutable $occurredAt,
    ) {
    }
}
