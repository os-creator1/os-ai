<?php

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\Messaging\TelnyxMessagingAdapter;
use App\Library\Voice\VoiceCallReconciler;
use App\Library\Voice\VoiceInboundRouter;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telnyx call-event webhooks for browser calling, reusing the Ed25519 verification the messaging webhooks
 * already use. Nothing is trusted before the signature checks out:
 *
 *   managed  → signed by the platform key (services.telnyx.webhook_public_key)
 *   BYO      → the channel is named by its opaque uid + HMAC token, and the body must be signed by THAT
 *              Agency's own stored public key — never by the platform key.
 *
 * The answer never says which part failed. Routing an incoming call to the assigned browser runs after
 * the response, so Telnyx is acknowledged immediately.
 */
class TelnyxVoiceWebhookController extends Controller
{
    public function managed(Request $request, VoiceCallReconciler $reconciler, VoiceInboundRouter $router): JsonResponse
    {
        $key = trim((string) config('services.telnyx.webhook_public_key'));

        if (! (bool) config('services.telnyx.voice_enabled') || $key === '') {
            return response()->json(['status' => 'ignored'], 200);
        }

        return $this->process($request, $key, null, $reconciler, $router);
    }

    public function agency(Request $request, string $channelUid, string $token, VoiceCallReconciler $reconciler, VoiceInboundRouter $router): JsonResponse
    {
        $channel = AgencyProspectingChannel::query()->where('uid', $channelUid)->first();

        if ($channel === null
            || ! AgencyProspectingWebhookToken::isValid($channel->uid, $token)
            || $channel->provider !== SendingServer::TYPE_TELNYX
            || $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED) {
            return response()->json([], 404);
        }

        $key = trim((string) $channel->webhook_public_key);

        if ($key === '') {
            return response()->json(['status' => 'rejected'], 403);
        }

        return $this->process($request, $key, $channel, $reconciler, $router);
    }

    private function process(Request $request, string $publicKey, ?AgencyProspectingChannel $channel, VoiceCallReconciler $reconciler, VoiceInboundRouter $router): JsonResponse
    {
        $raw = $request->getContent();

        if (! TelnyxMessagingAdapter::verifySignatureWithKey($publicKey, $raw, $request->headers->all())) {
            return response()->json(['status' => 'rejected'], 403);
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            return response()->json(['status' => 'rejected'], 400);
        }

        $outcome = $reconciler->handle($body, $channel);

        if ($outcome['result'] === 'malformed') {
            return response()->json(['status' => 'rejected'], 400);
        }

        if ($outcome['route'] !== null) {
            $route = $outcome['route'];

            app()->terminating(static fn () => $router->route($route['call'], $route['number'], $route['control_id']));
        }

        return response()->json(['status' => $outcome['result']], 200);
    }
}
