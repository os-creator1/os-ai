<?php

namespace Tests\Feature\Voice\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Voice\FakeVoiceClient;
use App\Library\Voice\VoiceProviderClient;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use App\Models\VoiceNumber;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Browser-calling fixtures. The Telnyx client is ALWAYS the in-memory fake; managed voice is switched on
 * with test-only config and a freshly generated Ed25519 keypair, so webhooks are genuinely signed and
 * no real account, number or call is ever involved.
 */
trait BuildsVoiceFixtures
{
    use BuildsAgencyByoChannels;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    protected FakeVoiceClient $telnyx;

    protected string $managedSecretKey;

    protected function setUpVoice(): void
    {
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $keypair = sodium_crypto_sign_keypair();
        $this->managedSecretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'services.telnyx.voice_enabled' => true,
            'services.telnyx.api_key' => 'platform-key-never-leaks',
            'services.telnyx.voice_connection_id' => 'platform-conn-1',
            'services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ]);

        $this->telnyx = new FakeVoiceClient();
        $this->app->instance(VoiceProviderClient::class, $this->telnyx);
    }

    /** @return array{0: Business, 1: Workspace, 2: User} a signed-in owner of an ordinary Business */
    protected function signedInBusiness(WorkspacePlanTier $tier = WorkspacePlanTier::Core, string $name = 'Harbor Lane Studios'): array
    {
        [$customer, $business, $workspace] = $this->tenant($tier, $name, $name . ' WS');

        return [$business, $workspace, $this->signIn($customer)];
    }

    protected function signIn(Customer $customer): User
    {
        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();
        $this->actingAs($user);

        return $user;
    }

    /** A managed, Active messaging number of the Business with a voice row already verified ready. */
    protected function managedVoiceNumber(Business $business, string $e164 = '+14155550199', bool $ready = true, ?int $inboundUserId = null): VoiceNumber
    {
        $resolver = app(BusinessMessagingIdentityResolver::class);
        $identity = $resolver->create($business, 'profile-' . Str::random(6), null, status: BusinessMessagingIdentityStatus::Active);
        $messaging = $resolver->attachNumber($identity, $e164, true, BusinessMessagingNumberStatus::Active);

        return VoiceNumber::create([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workspace_id' => $business->workspace_id,
            'source' => VoiceNumber::SOURCE_MANAGED,
            'messaging_number_id' => $messaging->id,
            'phone_number' => $messaging->phone_number,
            'voice_status' => $ready ? VoiceNumber::STATUS_READY : VoiceNumber::STATUS_UNVERIFIED,
            'inbound_user_id' => $inboundUserId,
        ]);
    }

    /** The Agency's own Telnyx number, set up for voice against its own connection. */
    protected function byoVoiceNumber(Workspace $workspace, Business $business, string $sender = '12025550142', bool $ready = true, ?int $inboundUserId = null): VoiceNumber
    {
        $channel = $this->byoChannel($workspace, ['sender' => $sender, 'key' => 'agency-private-key']);

        return VoiceNumber::create([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'workspace_id' => $workspace->id,
            'source' => VoiceNumber::SOURCE_BYO,
            'agency_channel_id' => $channel->id,
            'phone_number' => '+' . $sender,
            'webrtc_connection_id' => 'agency-conn-9',
            'voice_status' => $ready ? VoiceNumber::STATUS_READY : VoiceNumber::STATUS_UNVERIFIED,
            'inbound_user_id' => $inboundUserId,
        ]);
    }

    protected function voiceUrl(Workspace $workspace, Business $business, string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.voice.' . $name, array_merge([$workspace->uid, $business->uid], $extra));
    }

    protected function contactFor(Business $business, string $phone = '12025550111', string $first = 'Maya', string $last = 'Lopez'): Contacts
    {
        return $this->namedContact($business, $phone, $first, $last);
    }

    protected function startPayload(VoiceNumber $number, ?Contacts $contact = null, ?ChatBox $box = null, ?string $key = null): array
    {
        return array_filter([
            'number_uid' => $number->uid,
            'contact_uid' => $contact?->uid,
            'conversation_uid' => $box?->uid,
            'idempotency_key' => $key ?? Str::random(16),
        ]);
    }

    /** POST a genuinely signed Telnyx call event to the managed voice webhook. */
    protected function managedVoiceWebhook(string $eventId, string $type, array $payload, ?string $secretKey = null, ?int $timestamp = null)
    {
        return $this->signedVoicePost(route('voice.webhooks.telnyx_managed'), $eventId, $type, $payload, $secretKey ?? $this->managedSecretKey, $timestamp);
    }

    protected function byoVoiceWebhook(AgencyProspectingChannel $channel, string $eventId, string $type, array $payload, ?string $secretKey = null)
    {
        $url = route('prospecting.webhooks.agency_telnyx_voice', [
            $channel->uid,
            \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid),
        ]);

        return $this->signedVoicePost($url, $eventId, $type, $payload, $secretKey ?? $this->byoSecretKeys[(int) $channel->id]);
    }

    private function signedVoicePost(string $url, string $eventId, string $type, array $payload, string $secretKey, ?int $timestamp = null)
    {
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => ($payload['_at'] ?? now()->toIso8601String()), 'payload' => array_diff_key($payload, ['_at' => 1])]]);
        $ts = (string) ($timestamp ?? now()->getTimestamp());
        $signature = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $secretKey));

        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
            'HTTP_TELNYX_TIMESTAMP' => $ts,
        ], $body);
    }
}
