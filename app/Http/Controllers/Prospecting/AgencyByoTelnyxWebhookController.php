<?php

namespace App\Http\Controllers\Prospecting;

use App\Http\Controllers\Controller;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\Messaging\InboundWebhookAttributionResolver;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The webhook an agency points its OWN Telnyx messaging profile at. The URL names
 * one channel (opaque uid + HMAC token); the payload is then authenticated against
 * that agency's own stored Ed25519 public key inside the shared inbound resolver,
 * which also owns replay protection, conversation history, opt-outs and the
 * automation event. Nothing here decides tenancy: the channel's Workspace does.
 *
 * An unknown uid, a wrong token, a non-Telnyx channel or a disconnected one all
 * answer the same bare 404, so the endpoint reveals nothing about which exist.
 */
class AgencyByoTelnyxWebhookController extends Controller
{
    public function handle(Request $request, string $channelUid, string $token, InboundWebhookAttributionResolver $resolver): JsonResponse
    {
        $channel = AgencyProspectingChannel::query()->where('uid', $channelUid)->with('sendingServer')->first();

        if ($channel === null
            || ! AgencyProspectingWebhookToken::isValid($channel->uid, $token)
            || $channel->provider !== SendingServer::TYPE_TELNYX
            || $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED) {
            return response()->json([], 404);
        }

        return $resolver->handleAgencyByo($request, $channel);
    }
}
