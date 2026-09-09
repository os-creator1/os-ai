<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\DTO\OutboundMessageResult;
use App\Library\Messaging\Exceptions\MessagingIdentityUnresolvedException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Slice 3 §4.3/§4.4 — the production-shaped Telnyx adapter.
 *
 * Developed and exercised exclusively against Http::fake(); no live Telnyx
 * call, account, number, brand or campaign is ever created by this class or
 * by any test of it. It uses Laravel's Http facade, never raw curl.
 *
 * Activation requires two independent things (§4.4), checked once in the
 * constructor, before any request can be built:
 *
 *   1. config('messaging.managed_messaging_enabled') is true; and
 *   2. every required config('services.telnyx.*') key is present.
 *
 * Real credentials sitting in an environment are therefore, by themselves,
 * insufficient to send anything — the explicit switch must also be on. When
 * either gate fails the constructor throws, so no partially configured
 * adapter can exist to make a request (T-MSG-31, T-MSG-32).
 *
 * Exception messages name the missing config KEY, never a value, and no
 * result object carries a raw provider body (T-MSG-41).
 */
class TelnyxMessagingAdapter implements MessagingProviderAdapter
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    private const TIMEOUT_SECONDS = 15;

    /** Telnyx signs with Ed25519 over "timestamp|body". */
    private const SIGNATURE_HEADER = 'telnyx-signature-ed25519';

    private const TIMESTAMP_HEADER = 'telnyx-timestamp';

    private const MAX_SIGNATURE_AGE_SECONDS = 300;

    private readonly string $apiKey;

    private readonly string $webhookPublicKey;

    public function __construct()
    {
        if (! config('messaging.managed_messaging_enabled')) {
            throw MessagingProviderNotConfiguredException::disabled();
        }

        $apiKey = (string) (config('services.telnyx.api_key') ?? '');
        $webhookPublicKey = (string) (config('services.telnyx.webhook_public_key') ?? '');

        if ($apiKey === '') {
            throw MessagingProviderNotConfiguredException::missingKey('services.telnyx.api_key');
        }

        if ($webhookPublicKey === '') {
            throw MessagingProviderNotConfiguredException::missingKey('services.telnyx.webhook_public_key');
        }

        $this->apiKey = $apiKey;
        $this->webhookPublicKey = $webhookPublicKey;
    }

    public function send(OutboundMessageRequest $request): OutboundMessageResult
    {
        $payload = [
            'from' => $request->fromNumber,
            'to' => $request->toNumber,
            'text' => $request->body,
            'messaging_profile_id' => $request->messagingProfileId,
        ];

        if ($request->mediaUrls !== []) {
            $payload['media_urls'] = $request->mediaUrls;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(self::API_BASE . '/messages', $payload);
        } catch (\Throwable) {
            // A timeout or transport failure is genuinely ambiguous: the
            // provider may or may not have accepted it. Never Accepted.
            return OutboundMessageResult::rejected(ProviderErrorCategory::Retryable);
        }

        if ($response->successful()) {
            $providerMessageId = $response->json('data.id');

            if (is_string($providerMessageId) && $providerMessageId !== '') {
                return OutboundMessageResult::accepted($providerMessageId);
            }

            // A 2xx we cannot correlate is not a confirmed acceptance.
            return OutboundMessageResult::rejected(ProviderErrorCategory::Unknown);
        }

        return OutboundMessageResult::rejected(self::categorizeStatus($response->status()));
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $signature = self::header($headers, self::SIGNATURE_HEADER);
        $timestamp = self::header($headers, self::TIMESTAMP_HEADER);

        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > self::MAX_SIGNATURE_AGE_SECONDS) {
            return false;
        }

        $signatureBytes = base64_decode($signature, true);
        $publicKeyBytes = base64_decode($this->webhookPublicKey, true);

        if ($signatureBytes === false || $publicKeyBytes === false) {
            return false;
        }

        if (strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                $timestamp . '|' . $rawBody,
                $publicKeyBytes,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function parseInboundWebhook(string $rawBody): InboundWebhookEvent
    {
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            throw new MessagingIdentityUnresolvedException('Inbound webhook body is not valid JSON.');
        }

        $data = $decoded['data'] ?? null;
        $payload = is_array($data) ? ($data['payload'] ?? null) : null;

        if (! is_array($payload)) {
            throw new MessagingIdentityUnresolvedException('Inbound webhook body carries no data.payload object.');
        }

        $eventType = is_array($data) ? (string) ($data['event_type'] ?? '') : '';
        $kind = str_contains($eventType, 'received')
            ? InboundWebhookEventKind::MessageReceived
            : InboundWebhookEventKind::DeliveryStatus;

        // Telnyx delivers "to" as a list of destination objects on both the
        // received and finalized shapes; the first carries the destination
        // this platform's number mapping is keyed on.
        $to = $payload['to'] ?? [];
        $destination = is_array($to) ? ($to[0] ?? null) : null;
        $destinationNumber = is_array($destination) ? ($destination['phone_number'] ?? null) : null;
        $deliveryStatus = is_array($destination) ? ($destination['status'] ?? null) : null;

        $from = $payload['from'] ?? null;
        $fromNumber = is_array($from) ? ($from['phone_number'] ?? null) : null;

        $mediaUrls = [];
        foreach ((array) ($payload['media'] ?? []) as $medium) {
            if (is_array($medium) && isset($medium['url'])) {
                $mediaUrls[] = (string) $medium['url'];
            }
        }

        $occurredAtRaw = $payload['received_at'] ?? $payload['completed_at'] ?? $payload['sent_at'] ?? null;

        return new InboundWebhookEvent(
            kind: $kind,
            messagingProfileId: isset($payload['messaging_profile_id']) ? (string) $payload['messaging_profile_id'] : null,
            destinationNumber: $destinationNumber !== null ? (string) $destinationNumber : null,
            fromNumber: $fromNumber !== null ? (string) $fromNumber : null,
            body: isset($payload['text']) ? (string) $payload['text'] : null,
            mediaUrls: $mediaUrls,
            providerMessageId: isset($payload['id']) ? (string) $payload['id'] : null,
            deliveryStatus: $deliveryStatus !== null ? (string) $deliveryStatus : null,
            occurredAt: self::parseTimestamp($occurredAtRaw),
        );
    }

    private static function categorizeStatus(int $status): ProviderErrorCategory
    {
        return match (true) {
            $status === 401, $status === 403 => ProviderErrorCategory::Configuration,
            $status === 429, $status >= 500 => ProviderErrorCategory::Retryable,
            $status >= 400 => ProviderErrorCategory::Terminal,
            default => ProviderErrorCategory::Unknown,
        };
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $resolved = is_array($value) ? ($value[0] ?? null) : $value;

            return $resolved === null ? null : (string) $resolved;
        }

        return null;
    }

    private static function parseTimestamp(mixed $raw): CarbonImmutable
    {
        if (is_string($raw) && $raw !== '') {
            try {
                return CarbonImmutable::parse($raw);
            } catch (\Throwable) {
                // Fall through to now(); an unparseable provider timestamp is
                // not a reason to discard an otherwise verified event.
            }
        }

        return CarbonImmutable::now();
    }
}
