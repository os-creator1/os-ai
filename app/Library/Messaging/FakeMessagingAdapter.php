<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\DTO\OutboundMessageResult;
use Carbon\CarbonImmutable;

/**
 * Slice 3 §4.3 — the deterministic, in-memory adapter the test suite
 * exercises. It performs no I/O of any kind and holds no credential.
 *
 * Bound only inside a test's own setUp() via
 * $this->app->instance(MessagingProviderAdapter::class, new FakeMessagingAdapter()),
 * mirroring FakeAgencyProspectingMessageSender's established pattern.
 */
class FakeMessagingAdapter implements MessagingProviderAdapter
{
    /** @var list<OutboundMessageRequest> every send() call, in order */
    public array $sentRequests = [];

    /** @var list<array{rawBody: string, headers: array<string, mixed>}> */
    public array $signatureChecks = [];

    /**
     * Scripted rejections, keyed by operationKey, or '*' for every send.
     *
     * @var array<string, ProviderErrorCategory>
     */
    public array $rejections = [];

    /** Set false to make every signature check fail. */
    public bool $signatureValid = true;

    /** @var list<InboundWebhookEvent> */
    public array $queuedInboundWebhooks = [];

    private int $messageCounter = 0;

    public function send(OutboundMessageRequest $request): OutboundMessageResult
    {
        $this->sentRequests[] = $request;

        $rejection = $this->rejections[$request->operationKey] ?? $this->rejections['*'] ?? null;

        if ($rejection instanceof ProviderErrorCategory) {
            return OutboundMessageResult::rejected($rejection);
        }

        $this->messageCounter++;

        return OutboundMessageResult::accepted(sprintf('fake_msg_%06d', $this->messageCounter));
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $this->signatureChecks[] = ['rawBody' => $rawBody, 'headers' => $headers];

        return $this->signatureValid;
    }

    public function parseInboundWebhook(string $rawBody): InboundWebhookEvent
    {
        if ($this->queuedInboundWebhooks !== []) {
            return array_shift($this->queuedInboundWebhooks);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($rawBody, true) ?: [];

        return self::eventFromArray($decoded);
    }

    /**
     * Test helper — queue the exact event the next parse should return,
     * so a test need not hand-craft a provider-shaped body.
     */
    public function queueInboundWebhook(InboundWebhookEvent $event): void
    {
        $this->queuedInboundWebhooks[] = $event;
    }

    public function sentCount(): int
    {
        return count($this->sentRequests);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function eventFromArray(array $decoded): InboundWebhookEvent
    {
        $kind = ($decoded['kind'] ?? null) === InboundWebhookEventKind::DeliveryStatus->value
            ? InboundWebhookEventKind::DeliveryStatus
            : InboundWebhookEventKind::MessageReceived;

        return new InboundWebhookEvent(
            kind: $kind,
            messagingProfileId: isset($decoded['messaging_profile_id']) ? (string) $decoded['messaging_profile_id'] : null,
            destinationNumber: isset($decoded['to']) ? (string) $decoded['to'] : null,
            fromNumber: isset($decoded['from']) ? (string) $decoded['from'] : null,
            body: isset($decoded['body']) ? (string) $decoded['body'] : null,
            mediaUrls: array_values(array_map('strval', (array) ($decoded['media_urls'] ?? []))),
            providerMessageId: isset($decoded['provider_message_id']) ? (string) $decoded['provider_message_id'] : null,
            deliveryStatus: isset($decoded['delivery_status']) ? (string) $decoded['delivery_status'] : null,
            occurredAt: CarbonImmutable::now(),
        );
    }
}
