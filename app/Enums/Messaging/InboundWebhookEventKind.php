<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.3/§4.6.2 — which of the two inbound event shapes arrived.
 *
 * The kind decides what an already-existing (provider, provider_message_id)
 * row means: a replay for MessageReceived, the expected outbound operation
 * for DeliveryStatus (§4.6.3).
 */
enum InboundWebhookEventKind: string
{
    case MessageReceived = 'message_received';
    case DeliveryStatus = 'delivery_status';
}
