<?php

namespace Tests\Feature\AgencyOutreach\Concerns;

use App\Library\Security\SealedSecret;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

/**
 * An agency's OWN Telnyx account, entirely mocked: a sealed credential, a real
 * Ed25519 keypair so inbound webhooks can be genuinely signed, and Http::fake()
 * for every Telnyx call. No live provider is ever contacted.
 */
trait BuildsAgencyByoChannels
{
    /** @var array<int, string> secret signing key (raw bytes) by channel id */
    protected array $byoSecretKeys = [];

    protected function byoChannel(Workspace $workspace, array $o = []): AgencyProspectingChannel
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));

        $server = SendingServer::create([
            'name' => 'Telnyx',
            'settings' => SendingServer::TYPE_TELNYX,
            'status' => true,
            'plain' => true,
            'api_key' => ($o['sealed'] ?? true) ? SealedSecret::seal($o['key'] ?? 'agency-key-' . uniqid()) : ($o['key'] ?? 'agency-key-' . uniqid()),
            'c1' => $o['profile'] ?? 'profile-' . uniqid(),
            'user_id' => $workspace->owner_user_id,
        ]);

        $channel = AgencyProspectingChannel::create([
            'workspace_id' => $workspace->id,
            'sending_server_id' => $server->id,
            'provider' => SendingServer::TYPE_TELNYX,
            'sender_number' => $o['sender'] ?? ('1202555' . random_int(1000, 9999)),
            'status' => $o['status'] ?? AgencyProspectingChannel::STATUS_ACTIVE,
            'created_by_user_id' => $workspace->owner_user_id,
            'is_default_sender' => $o['default'] ?? false,
            'webhook_public_key' => ($o['webhook_key'] ?? true) ? SealedSecret::seal($publicKey) : null,
            'credentials_verified_at' => array_key_exists('verified', $o) ? $o['verified'] : now(),
        ]);

        $this->byoSecretKeys[(int) $channel->id] = sodium_crypto_sign_secretkey($keypair);

        return $channel->fresh();
    }

    /** Mock Telnyx: accepts every message with a fresh id, and answers the read-only checks. */
    protected function fakeTelnyx(int $messageStatus = 200): void
    {
        $seq = 0;

        Http::fake([
            'api.telnyx.com/v2/messages' => function () use (&$seq, $messageStatus) {
                $seq++;

                return $messageStatus === 200
                    ? Http::response(['data' => ['id' => 'tx-msg-' . $seq]], 200)
                    : Http::response(['errors' => [['detail' => 'nope']]], $messageStatus);
            },
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => ['id' => 'p']], 200),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'n1', 'status' => 'active']]], 200),
        ]);
    }

    /** @return list<\Illuminate\Http\Client\Request> */
    protected function telnyxMessageRequests(): array
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/v2/messages'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    protected function signedWebhook(AgencyProspectingChannel $channel, array $payload, ?string $secretKey = null, ?string $token = null, ?string $uid = null)
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->getTimestamp();
        $signature = base64_encode(sodium_crypto_sign_detached($timestamp . '|' . $body, $secretKey ?? $this->byoSecretKeys[(int) $channel->id]));
        $url = route('prospecting.webhooks.agency_telnyx', [
            $uid ?? $channel->uid,
            $token ?? \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid),
        ]);

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
            'HTTP_TELNYX_TIMESTAMP' => $timestamp,
        ], $body);
    }

    protected function receivedPayload(AgencyProspectingChannel $channel, string $text, string $id = 'in-1', string $from = '+12025551000', ?string $profile = null, ?string $to = null): array
    {
        return ['data' => ['event_type' => 'message.received', 'payload' => [
            'id' => $id,
            'direction' => 'inbound',
            'messaging_profile_id' => $profile ?? $channel->sendingServer->c1,
            'text' => $text,
            'from' => ['phone_number' => $from],
            'to' => [['phone_number' => $to ?? '+' . $channel->sender_number]],
            'received_at' => now()->toIso8601String(),
        ]]];
    }

    protected function deliveryPayload(AgencyProspectingChannel $channel, string $messageId, string $status): array
    {
        return ['data' => ['event_type' => 'message.finalized', 'payload' => [
            'id' => $messageId,
            'direction' => 'outbound',
            'messaging_profile_id' => $channel->sendingServer->c1,
            'to' => [['phone_number' => '+12025551000', 'status' => $status]],
            'completed_at' => now()->toIso8601String(),
        ]]];
    }
}
