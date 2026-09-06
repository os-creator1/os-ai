<?php

namespace App\Http\Controllers\Prospecting;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Jobs\AgencyProspectingRespondJob;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\AgencyProspecting\AgencyProspectStopAction;
use App\Library\AgencyProspecting\AgencyProspectStopDetector;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
use App\Models\AgencyProspectMessage;
use App\Models\SendingServer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

/**
 * Agency AI Prospecting runtime pass — a dedicated inbound path, entirely
 * separate from the legacy DLRController::inboundDLR() pipeline (never
 * touches Contacts, ChatBox, Reports, or LegacyBusinessResolver). The URL
 * identifies an exact channel by opaque uid plus an unguessable HMAC token
 * (AgencyProspectingWebhookToken) — never a bare sequential database ID.
 * No Laravel session/auth is expected; every tenancy decision resolves
 * through the channel's own explicit workspace_id, never a webhook payload
 * field.
 *
 * Order (task-required safety-first sequence): authenticate -> identify
 * channel -> normalize phones -> idempotency check -> resolve Workspace ->
 * resolve prospect -> resolve exactly one active membership -> persist
 * inbound -> deterministic STOP/negative detection -> only if not stopped,
 * enqueue AI response after commit. The webhook itself never waits on an
 * AI response.
 */
class AgencyProspectingWebhookController extends Controller
{
    public function twilio(Request $request, string $channelUid, string $token): Response
    {
        $channel = $this->resolveChannel($channelUid, $token, SendingServer::TYPE_TWILIO);

        if ($channel === null) {
            return response('', 404);
        }

        if (! $this->verifyTwilioSignature($request, $channel)) {
            Log::warning('Agency Prospecting Twilio webhook signature verification failed.', ['channel_uid' => $channelUid]);

            return response('', 404);
        }

        return $this->process(
            $channel,
            (string) $request->input('From'),
            (string) $request->input('To'),
            (string) $request->input('Body'),
            $request->input('MessageSid'),
        );
    }

    public function telnyx(Request $request, string $channelUid, string $token): Response
    {
        $channel = $this->resolveChannel($channelUid, $token, SendingServer::TYPE_TELNYX);

        if ($channel === null) {
            return response('', 404);
        }

        $payload = json_decode($request->getContent(), true);
        $direction = $payload['data']['payload']['direction'] ?? null;

        if ($direction !== 'inbound') {
            // A Telnyx outbound delivery-status callback for this same
            // route is a valid, expected event — acknowledge, do nothing.
            return response('', 200);
        }

        $from = $payload['data']['payload']['from']['phone_number'] ?? null;
        $to = $payload['data']['payload']['to'][0]['phone_number'] ?? null;
        $body = $payload['data']['payload']['text'] ?? null;
        $providerMessageId = $payload['data']['payload']['id'] ?? null;

        return $this->process($channel, (string) $from, (string) $to, (string) $body, $providerMessageId);
    }

    private function resolveChannel(string $channelUid, string $token, string $expectedProvider): ?AgencyProspectingChannel
    {
        if (! AgencyProspectingWebhookToken::isValid($channelUid, $token)) {
            return null;
        }

        $channel = AgencyProspectingChannel::where('uid', $channelUid)->first();

        if ($channel === null || $channel->provider !== $expectedProvider) {
            return null;
        }

        return $channel;
    }

    private function verifyTwilioSignature(Request $request, AgencyProspectingChannel $channel): bool
    {
        $authToken = $channel->sendingServer?->auth_token;

        if (empty($authToken)) {
            return false;
        }

        $signature = $request->header('X-Twilio-Signature');

        if (empty($signature)) {
            return false;
        }

        $validator = new RequestValidator($authToken);

        return $validator->validate($signature, $request->fullUrl(), $request->all());
    }

    private function process(AgencyProspectingChannel $channel, string $from, string $to, string $body, ?string $providerMessageId): Response
    {
        // Hard checks, in order — every failure is provider-safe (200,
        // never leaking which check failed) except an already-handled
        // unknown channel/token/provider mismatch (404, resolved above).
        if (! $channel->isActive() || $channel->sendingServer === null || ! $channel->sendingServer->status) {
            return response('', 200);
        }

        $workspace = $channel->workspace;

        if ($workspace === null || ! $workspace->is_active) {
            return response('', 200);
        }

        $entitlementManager = app(EntitlementManager::class);

        if (! $entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed) {
            return response('', 200);
        }

        if ($providerMessageId === null || trim((string) $providerMessageId) === '') {
            return response('', 200);
        }

        // Idempotency — a duplicate provider delivery of the exact same
        // message must never be processed twice.
        if (AgencyProspectMessage::where('provider_message_id', $providerMessageId)->exists()) {
            return response('', 200);
        }

        $normalizedFrom = AgencyProspectPhoneNormalizer::normalize($from);
        $normalizedTo = AgencyProspectPhoneNormalizer::normalize($to);

        if ($normalizedFrom === null || $normalizedTo === null) {
            return response('', 200);
        }

        if ($normalizedTo !== $channel->sender_number) {
            return response('', 200);
        }

        $body = mb_substr(trim($body), 0, 1600);

        if ($body === '') {
            return response('', 200);
        }

        $prospect = AgencyProspect::where('workspace_id', $workspace->id)->where('phone', $normalizedFrom)->first();

        if ($prospect === null) {
            // Unknown From — never guess/create a prospect from an
            // inbound message.
            return response('', 200);
        }

        $activeMemberships = AgencyProspectCampaignMember::where('prospect_id', $prospect->id)
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
            ->get();

        if ($activeMemberships->count() !== 1) {
            // Zero or ambiguous (>1) active memberships — do not guess
            // campaign attribution. Still return provider-safe 200.
            Log::info('Agency Prospecting inbound message has no single active membership; no reply sent.', [
                'workspace_id' => $workspace->id,
                'prospect_id' => $prospect->id,
                'active_membership_count' => $activeMemberships->count(),
            ]);

            return response('', 200);
        }

        $member = $activeMemberships->first();

        $message = AgencyProspectMessage::create([
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $member->id,
            'channel_id' => $channel->id,
            'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
            'provider_message_id' => $providerMessageId,
            'body' => $body,
            'status' => AgencyProspectMessage::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        DB::transaction(function () use ($member): void {
            AgencyProspectCampaignMember::where('id', $member->id)->update(['last_inbound_at' => now()]);
        });

        // Deterministic opt-out/negative detection — always before AI,
        // never dependent on one.
        $isHardStop = AgencyProspectStopDetector::isHardStop($body)
            || ($member->stage->value >= 3 && AgencyProspectStopDetector::isSoftNegative($body));

        if ($isHardStop) {
            app(AgencyProspectStopAction::class)->apply($prospect);

            return response('', 200);
        }

        if ($prospect->status === AgencyProspectStatus::Active) {
            AgencyProspectingRespondJob::dispatch($member->id);
        }

        return response('', 200);
    }
}
