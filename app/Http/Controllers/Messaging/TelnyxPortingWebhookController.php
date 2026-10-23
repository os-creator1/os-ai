<?php

namespace App\Http\Controllers\Messaging;

use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Http\Controllers\Controller;
use App\Library\Messaging\MessagingWebhookRejectionRecorder;
use App\Library\Messaging\PortIn\PortInOrderManager;
use App\Library\Messaging\TelnyxMessagingAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Telnyx `porting_order.*` webhooks. Unauthenticated at the door, so: Ed25519 signature verified FIRST (same key and
 * timestamp-age rule as managed messaging), then parsed, then applied idempotently by event id. A bad signature or body
 * is recorded and refused; nothing is attributed to any Business unless the carrier's own order id matches one we track.
 */
final class TelnyxPortingWebhookController extends Controller
{
    public function __construct(
        private readonly PortInOrderManager $orders,
        private readonly MessagingWebhookRejectionRecorder $rejections,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $key = trim((string) config('services.telnyx.webhook_public_key'));

        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = is_array($value) ? ($value[0] ?? null) : $value;
        }

        if ($key === '') {
            return response()->json(['status' => 'ignored'], 200); // not configured: fail closed, record nothing
        }

        if (! TelnyxMessagingAdapter::verifySignatureWithKey($key, $raw, $headers)) {
            $this->rejections->record(WebhookRejectionReason::InvalidSignature, MessagingProvider::Telnyx, $raw);

            return response()->json(['status' => 'rejected'], 403);
        }

        $body = json_decode($raw, true);
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $eventId = is_array($data) ? (string) ($data['id'] ?? '') : '';
        $type = is_array($data) ? (string) ($data['event_type'] ?? '') : '';
        $payload = is_array($data) && is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $orderId = (string) ($payload['id'] ?? '');
        $status = $payload['status']['value'] ?? ($payload['status'] ?? null);

        if ($eventId === '' || $orderId === '' || ! is_string($status) || ! str_starts_with($type, 'porting_order.')) {
            $this->rejections->record(WebhookRejectionReason::MalformedPayload, MessagingProvider::Telnyx, $raw);

            return response()->json(['status' => 'rejected'], 400);
        }

        // Only a status change moves an order; comments and other porting events are acknowledged and not applied.
        if ($type !== 'porting_order.status_changed') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $foc = $payload['activation_settings']['foc_datetime_actual'] ?? $payload['activation_settings']['foc_datetime_requested'] ?? null;
        $result = $this->orders->applyProviderStatus($orderId, $status, $eventId, is_string($foc) ? Carbon::parse($foc) : null);

        return response()->json(['status' => $result], 200);
    }
}
