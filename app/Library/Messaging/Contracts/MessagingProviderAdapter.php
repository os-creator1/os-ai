<?php

namespace App\Library\Messaging\Contracts;

use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\DTO\OutboundMessageResult;

/**
 * Slice 3 §4.3 — the provider-neutral transport boundary.
 *
 * Exactly three methods. Number search/order and registration submission are
 * Slice 4's own interface extension and are deliberately absent here — not
 * as inert stubs, not as dead code paths (§4.1).
 */
interface MessagingProviderAdapter
{
    public function send(OutboundMessageRequest $request): OutboundMessageResult;

    /**
     * Provider-specific cryptographic verification.
     *
     * Returns false — never throws — on any missing header, malformed
     * signature, or timestamp outside the allowed skew window.
     *
     * @param array<string, mixed> $headers
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool;

    /**
     * Turns an already-verified raw body into a normalized event.
     *
     * @throws \App\Library\Messaging\Exceptions\MessagingIdentityUnresolvedException when the body is not the expected shape
     */
    public function parseInboundWebhook(string $rawBody): InboundWebhookEvent;
}
