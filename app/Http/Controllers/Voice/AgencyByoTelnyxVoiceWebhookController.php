<?php

namespace App\Http\Controllers\Voice;

use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Http\Controllers\Controller;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\Messaging\MessagingWebhookRejectionRecorder;
use App\Library\Messaging\TelnyxMessagingAdapter;
use App\Library\Voice\BrowserCallOrchestrator;
use App\Library\Voice\VoiceCallStatusRecorder;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Call Control webhook an agency's OWN Telnyx account sends call events to. The URL names one channel (opaque uid +
 * HMAC token); the owning channel is resolved FIRST, then the payload is authenticated against THAT agency's own Ed25519
 * public key, and only then applied — and only to calls placed through that same channel (`forChannel`), so an event can
 * never be attributed to another tenant's call. Replays are idempotent by event id in the shared recorders.
 *
 * Unknown uid, wrong token, non-Telnyx, disconnected, or a channel with no voice setup all answer the same bare 404.
 * Outbound browser calling only: inbound routing is platform-managed and is never reached from here.
 */
final class AgencyByoTelnyxVoiceWebhookController extends Controller
{
    public function __construct(
        private readonly VoiceCallStatusRecorder $calls,
        private readonly BrowserCallOrchestrator $browserCalls,
        private readonly MessagingWebhookRejectionRecorder $rejections,
    ) {
    }

    public function handle(Request $request, string $channelUid, string $token): JsonResponse
    {
        $channel = AgencyProspectingChannel::query()->where('uid', $channelUid)->first();

        if ($channel === null
            || ! AgencyProspectingWebhookToken::isValid($channel->uid, $token)
            || $channel->provider !== SendingServer::TYPE_TELNYX
            || $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED
            || trim((string) $channel->voice_connection_id) === '') {
            return response()->json([], 404);
        }

        $raw = $request->getContent();
        $key = trim((string) $channel->webhook_public_key);
        if ($key === '' || ! AgencyByoSender::hasWebhookKey($channel)) {
            return response()->json(['status' => 'ignored'], 200); // no key to authenticate with: fail closed, record nothing
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

        // A correctly signed event that reached this channel's own URL proves the webhook is wired and the key is right.
        if ($channel->voice_webhook_verified_at === null) {
            $channel->forceFill(['voice_webhook_verified_at' => now()])->save();
        }

        $occurredAt = is_string($data['occurred_at'] ?? null) ? $data['occurred_at'] : null;
        $channelId = (int) $channel->id;

        $browser = $this->browserCalls->apply($eventId, $type, $callControlId, $payload, $occurredAt, $channelId);
        if ($browser !== null) {
            return response()->json(['status' => $browser], 200);
        }

        return response()->json(['status' => $this->calls->apply($eventId, $type, $callControlId, $payload, $occurredAt, $channelId)], 200);
    }
}
