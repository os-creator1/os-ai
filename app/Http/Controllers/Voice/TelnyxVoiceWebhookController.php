<?php

namespace App\Http\Controllers\Voice;

use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Http\Controllers\Controller;
use App\Library\Messaging\MessagingWebhookRejectionRecorder;
use App\Library\Messaging\TelnyxMessagingAdapter;
use App\Library\Voice\VoiceCallStatusRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telnyx Call Control `call.*` webhooks. Unauthenticated at the door, so: Ed25519 signature verified FIRST (same key and
 * timestamp-age rule as the messaging and porting webhooks), then parsed, then applied idempotently by event id. Nothing
 * is attributed to a Business unless the carrier's call_control_id matches a call this platform placed.
 */
final class TelnyxVoiceWebhookController extends Controller
{
    public function __construct(
        private readonly VoiceCallStatusRecorder $calls,
        private readonly MessagingWebhookRejectionRecorder $rejections,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $key = trim((string) config('services.telnyx.webhook_public_key'));

        if ($key === '') {
            return response()->json(['status' => 'ignored'], 200); // not configured: fail closed, record nothing
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = is_array($value) ? ($value[0] ?? null) : $value;
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
        $callControlId = (string) ($payload['call_control_id'] ?? '');

        if ($eventId === '' || ! str_starts_with($type, 'call.')) {
            $this->rejections->record(WebhookRejectionReason::MalformedPayload, MessagingProvider::Telnyx, $raw);

            return response()->json(['status' => 'rejected'], 400);
        }

        return response()->json(['status' => $this->calls->apply($eventId, $type, $callControlId, $payload, is_string($data['occurred_at'] ?? null) ? $data['occurred_at'] : null)], 200);
    }
}
