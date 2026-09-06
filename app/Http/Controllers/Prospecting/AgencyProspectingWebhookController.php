<?php

namespace App\Http\Controllers\Prospecting;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
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
use Illuminate\Database\UniqueConstraintViolationException;
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

        // Correction 1 — the candidate conversation must be open on THIS
        // exact incoming channel, and a Draft enrollment (never sent,
        // never a real conversation) must never count as competition for
        // attribution. A Paused campaign still owns its conversation (it
        // reserves the prospect for the one-open-conversation invariant)
        // and inbound must still be attributable to it — sending an AI
        // sales reply is what pause suppresses, never inbound capture.
        $candidates = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
            ->where('prospect_id', $prospect->id)
            ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
            ->whereHas('campaign', function ($query) use ($channel): void {
                $query->where('channel_id', $channel->id)
                    ->whereIn('status', [
                        AgencyProspectCampaignStatus::Active->value,
                        AgencyProspectCampaignStatus::Paused->value,
                    ]);
            })
            ->get();

        if ($candidates->count() !== 1) {
            // Zero (wrong channel, or only a Draft/foreign-channel
            // membership exists) or ambiguous (>1) open conversations on
            // this exact channel — do not guess campaign attribution.
            // Still return provider-safe 200.
            Log::info('Agency Prospecting inbound message has no single open conversation on this channel; no reply sent.', [
                'workspace_id' => $workspace->id,
                'prospect_id' => $prospect->id,
                'channel_id' => $channel->id,
                'candidate_count' => $candidates->count(),
            ]);

            return response('', 200);
        }

        $member = $candidates->first();
        $campaign = $member->campaign;

        // Correction 1 — atomic claim: a unique DB constraint on
        // provider_message_id is the actual race-proof guarantee (the
        // pre-check is only a fast path for the common non-racing case).
        // A concurrent duplicate delivery loses the INSERT race and is
        // still provider-safe (200), never a 500, never processed twice.
        if (AgencyProspectMessage::where('provider_message_id', $providerMessageId)->exists()) {
            return response('', 200);
        }

        try {
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
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent delivery of the exact same
            // provider message — already recorded, never processed twice.
            return response('', 200);
        }

        DB::transaction(function () use ($member): void {
            AgencyProspectCampaignMember::where('id', $member->id)->update(['last_inbound_at' => now()]);
        });

        // Correction 2, Section 7 — the hard-stop/soft-negative decision,
        // and any soft_negative_count mutation, happen inside ONE
        // transaction against a freshly LOCKED member row. Two
        // simultaneous early-stage soft negatives can no longer both read
        // count=0, both decide "first", and both enqueue AI: the second
        // request's lockForUpdate() blocks until the first commits, then
        // observes the already-incremented count and correctly stops
        // instead. Always before AI, never dependent on one, and never
        // blocked by a Paused campaign (unsubscribe must always be
        // captured).
        $stopDecision = DB::transaction(function () use ($member, $body): bool {
            $locked = AgencyProspectCampaignMember::where('id', $member->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isTerminal()) {
                return false;
            }

            $stage = $locked->stage->value;
            $isHardStop = AgencyProspectStopDetector::isHardStop($body);
            $isSoftNegative = ! $isHardStop && AgencyProspectStopDetector::isSoftNegative($body);

            if ($isHardStop || ($isSoftNegative && $stage >= 3)) {
                return true;
            }

            if ($isSoftNegative) {
                // Stage 1/2 — bounded repeated-soft-negative handling: the
                // AI may respond at most once; a second soft negative at
                // any later point stops the conversation before any
                // further AI involvement. The count is never reset just
                // because the AI replied.
                if ($locked->soft_negative_count >= 1) {
                    return true;
                }

                $locked->increment('soft_negative_count');
            }

            return false;
        });

        if ($stopDecision) {
            app(AgencyProspectStopAction::class)->apply($prospect);

            return response('', 200);
        }

        // A Paused campaign still records inbound and still honors STOP
        // above, but must never trigger an AI sales reply.
        if ($campaign === null || $campaign->status !== AgencyProspectCampaignStatus::Active) {
            return response('', 200);
        }

        if ($prospect->status === AgencyProspectStatus::Active) {
            AgencyProspectingRespondJob::dispatch($member->id, $message->id);
        }

        return response('', 200);
    }
}
