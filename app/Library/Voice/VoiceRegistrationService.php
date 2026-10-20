<?php

namespace App\Library\Voice;

use App\Models\Business;
use App\Models\User;
use App\Models\VoiceNumber;
use App\Models\VoiceRegistration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One Telnyx telephony credential per (user, Business, account scope): a SIP identity is never shared
 * between users, Businesses, or between the managed and a BYO account. The browser only ever receives
 * a short-lived JWT minted for its own credential; the credential id and SIP username stay server-side
 * and no SIP password is ever requested or stored.
 */
final class VoiceRegistrationService
{
    public function __construct(
        private readonly VoiceProviderClient $provider,
        private readonly VoiceCredentials $credentials,
    ) {
    }

    /** @return array{token: string, expires_at: ?int} */
    public function issueToken(User $user, Business $business, VoiceNumber $number): array
    {
        $creds = $this->credentials->forNumber($number, $business);
        $this->assertBrowserCannotDial($creds);
        $registration = $this->registrationFor($user, $business, $number, $creds);

        try {
            $token = $this->mint($creds['api_key'], $registration);
        } catch (VoiceProviderException $e) {
            if ($e->reason !== 'token_failed') {
                throw $e;
            }

            // The credential may have been removed at Telnyx: rebuild it once, then give up with a clear message.
            $this->retire($registration, $creds['api_key']);
            $registration = $this->registrationFor($user, $business, $number, $creds);
            $token = $this->mint($creds['api_key'], $registration);
        }

        return ['token' => $token, 'expires_at' => self::jwtExpiry($token)];
    }

    /**
     * THE enforcement point for outbound authorization. A browser that holds a token can ask the Telnyx SDK
     * to dial anything its credential connection allows. So no token is issued unless Telnyx itself says the
     * credential connection has NO outbound voice profile (it cannot reach the phone network), re-checked at
     * most every five minutes. Anything unconfirmed fails closed.
     *
     * @param  array{api_key: string, connection_id: string, app_id: string, public_key: string}  $creds
     */
    private function assertBrowserCannotDial(array $creds): void
    {
        $key = 'voice:locked:' . sha1($creds['connection_id']);

        if (Cache::get($key) === true) {
            return;
        }

        $facts = $this->provider->inspectCredentialConnection($creds['api_key'], $creds['connection_id']);

        if (! $facts['exists'] || ! $facts['active']) {
            throw new VoiceUnavailableException('voice_connection_missing', 'The Telnyx voice connection is missing or switched off.');
        }

        if (! $facts['outbound_known']) {
            throw new VoiceUnavailableException('outbound_unconfirmed', 'Telnyx did not confirm that browser calling is locked down on this voice connection, so calling stays off.');
        }

        if ($facts['outbound_profile_id'] !== null) {
            throw new VoiceUnavailableException('direct_dialing_open', 'This voice connection has an outbound voice profile, which would let browsers dial directly. Remove it in Telnyx; MotionGrove places calls itself.');
        }

        Cache::put($key, true, 300);
    }

    /** Heartbeat from the ONE leading browser tab; this is what "online for inbound" means. */
    public function heartbeat(User $user, Business $business, VoiceNumber $number): bool
    {
        return VoiceRegistration::query()
            ->where('user_id', (int) $user->id)
            ->where('business_id', (int) $business->id)
            ->where('scope_key', $number->scopeKey())
            ->whereNull('revoked_at')
            ->update(['last_seen_at' => now()]) > 0;
    }

    /** Logout, workspace switch or tab close: the user is no longer reachable in a browser. */
    public function goOffline(User $user, ?Business $business = null): void
    {
        VoiceRegistration::query()
            ->where('user_id', (int) $user->id)
            ->when($business !== null, fn ($q) => $q->where('business_id', (int) $business->id))
            ->update(['last_seen_at' => null]);
    }

    /** The user's live registration for a number's scope, if any — used to route inbound calls. */
    public function onlineRegistration(int $userId, VoiceNumber $number): ?VoiceRegistration
    {
        $registration = VoiceRegistration::query()
            ->where('user_id', $userId)
            ->where('business_id', (int) $number->business_id)
            ->where('scope_key', $number->scopeKey())
            ->first();

        return $registration !== null && $registration->isOnline() ? $registration : null;
    }

    /** @param array{api_key: string, connection_id: string, public_key: string} $creds */
    private function registrationFor(User $user, Business $business, VoiceNumber $number, array $creds): VoiceRegistration
    {
        $registration = VoiceRegistration::query()
            ->where('user_id', (int) $user->id)
            ->where('business_id', (int) $business->id)
            ->where('scope_key', $number->scopeKey())
            ->first();

        if ($registration !== null && $registration->revoked_at === null && $registration->connection_id === $creds['connection_id']) {
            return $registration;
        }

        if ($registration !== null) {
            $this->retire($registration, $creds['api_key']);
        }

        $created = $this->provider->createTelephonyCredential(
            $creds['api_key'],
            $creds['connection_id'],
            'MotionGrove user ' . $user->id,
            'mg-voice-b' . $business->id . '-u' . $user->id,
        );

        return VoiceRegistration::query()->create([
            'uid' => (string) Str::uuid(),
            'user_id' => (int) $user->id,
            'business_id' => (int) $business->id,
            'scope_key' => $number->scopeKey(),
            'connection_id' => $creds['connection_id'],
            'telnyx_credential_id' => $created['id'],
            'sip_username' => $created['sip_username'],
        ]);
    }

    private function mint(string $apiKey, VoiceRegistration $registration): string
    {
        return $this->provider->mintToken($apiKey, (string) $registration->getRawOriginal('telnyx_credential_id'));
    }

    private function retire(VoiceRegistration $registration, string $apiKey): void
    {
        try {
            $this->provider->deleteTelephonyCredential($apiKey, (string) $registration->getRawOriginal('telnyx_credential_id'));
        } catch (VoiceProviderException) {
            // Best effort: the row is removed either way, so it can never be handed out again.
        }

        $registration->delete();
    }

    /** The JWT's own `exp` claim, when it has one; the browser also re-authenticates on any auth error. */
    private static function jwtExpiry(string $jwt): ?int
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp']) ? (int) $payload['exp'] : null;
    }
}
