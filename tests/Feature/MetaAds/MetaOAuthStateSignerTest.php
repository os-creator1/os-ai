<?php

namespace Tests\Feature\MetaAds;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Library\MetaAds\MetaOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessMetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §3 / M2 — the Meta OAuth state is signed,
 * single-use, TTL-bound, bound to Business + initiating user, and isolated
 * from the Google signer in BOTH directions.
 */
class MetaOAuthStateSignerTest extends TestCase
{
    use CreatesMetaAdsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();
    }

    private function signer(): MetaOAuthStateSigner
    {
        return app(MetaOAuthStateSigner::class);
    }

    private function pendingConnection(\App\Models\Business $business, int $actorId): BusinessMetaConnection
    {
        return BusinessMetaConnection::create([
            'business_id' => $business->id,
            'state' => 'pending',
            'connected_by_user_id' => $actorId,
        ]);
    }

    private function payloadOf(string $state): array
    {
        return json_decode((string) base64_decode(strtr(explode('.', $state)[0], '-_', '+/')), true);
    }

    private function resign(array $payload, ?string $key = null): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $key ??= hash('sha256', 'meta_ads:' . config('app.key'));

        return $encoded . '.' . hash_hmac('sha256', $encoded, $key);
    }

    public function test_issue_writes_nonce_and_expiry_and_the_payload_is_exactly_the_minimum(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->pendingConnection($business, $actor);

        $state = $this->signer()->issue($connection, $actor);

        $row = $connection->fresh();
        $this->assertNotNull($row->oauth_state_nonce);
        $this->assertTrue($row->oauth_state_expires_at->isFuture());
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($row->oauth_state_expires_at, false), 5);

        $payload = $this->payloadOf($state);
        $this->assertSame(['b', 'u', 'p', 'n', 'e'], array_keys($payload));
        $this->assertSame((int) $business->id, $payload['b']);
        $this->assertSame($actor, $payload['u']);
        $this->assertSame('meta_ads', $payload['p']);
        $this->assertSame($row->oauth_state_nonce, $payload['n']);

        $this->assertSame($payload, $this->signer()->verify($state));
    }

    public function test_a_state_is_single_use_and_replay_fails(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->pendingConnection($business, $actor);

        $payload = $this->signer()->verify($this->signer()->issue($connection, $actor));

        $this->assertTrue($this->signer()->consume($payload));
        $this->assertFalse($this->signer()->consume($payload), 'second consume must fail');
        $this->assertNull($connection->fresh()->oauth_state_nonce);
    }

    public function test_a_newer_issue_kills_the_older_state(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->pendingConnection($business, $actor);

        $old = $this->signer()->verify($this->signer()->issue($connection, $actor));
        $new = $this->signer()->verify($this->signer()->issue($connection->fresh(), $actor));

        $this->assertFalse($this->signer()->consume($old));
        $this->assertTrue($this->signer()->consume($new));
    }

    public function test_an_expired_state_does_not_verify_and_cannot_be_consumed(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->pendingConnection($business, $actor);

        $state = $this->signer()->issue($connection, $actor);
        $payload = $this->signer()->verify($state);
        $this->assertNotNull($payload);

        Carbon::setTestNow(now()->addSeconds($this->signer()->ttlSeconds() + 5));

        try {
            $this->assertNull($this->signer()->verify($state));
            $this->assertFalse($this->signer()->consume($payload), 'stored expiry is re-checked in the UPDATE');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ttl_comes_from_config_and_fails_closed_to_the_default(): void
    {
        config(['meta_ads.oauth.state_ttl_seconds' => 120]);
        $this->assertSame(120, $this->signer()->ttlSeconds());

        foreach ([0, 5, 99999, 'abc', null] as $bad) {
            config(['meta_ads.oauth.state_ttl_seconds' => $bad]);
            $this->assertSame(600, $this->signer()->ttlSeconds());
        }
    }

    public function test_a_forged_or_tampered_state_does_not_verify(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $state = $this->signer()->issue($this->pendingConnection($business, $actor), $actor);
        $payload = $this->payloadOf($state);

        // Tampered payload (another Business), original signature.
        $forgedPayload = array_merge($payload, ['b' => $payload['b'] + 1]);
        $encoded = rtrim(strtr(base64_encode((string) json_encode($forgedPayload)), '+/', '-_'), '=');
        $this->assertNull($this->signer()->verify($encoded . '.' . explode('.', $state)[1]));

        // Correct shape signed with a wrong key.
        $this->assertNull($this->signer()->verify($this->resign($payload, 'not-the-key')));

        // Garbage.
        foreach ([null, '', 'x', 'a.b', '.', $state . 'x', 'a.b.c', str_repeat('A', 2000)] as $junk) {
            $this->assertNull($this->signer()->verify($junk));
        }

        // Correctly signed but structurally wrong.
        foreach ([
            array_merge($payload, ['b' => '1']),
            array_merge($payload, ['u' => null]),
            array_merge($payload, ['n' => '']),
            array_merge($payload, ['e' => now()->subMinute()->getTimestamp()]),
            array_merge($payload, ['p' => 'google_ads']),
            array_merge($payload, ['p' => 'business_profile']),
        ] as $bad) {
            $this->assertNull($this->signer()->verify($this->resign($bad)));
        }

        $this->assertNotNull($this->signer()->verify($this->resign($payload)), 'control: the helper signs validly');
    }

    public function test_another_business_or_another_actor_cannot_consume_a_state(): void
    {
        [$customerA, $businessA] = $this->metaTenant('A');
        [$customerB, $businessB] = $this->metaTenant('B');
        $actorA = (int) $customerA->user_id;
        $actorB = (int) $customerB->user_id;

        $connectionA = $this->pendingConnection($businessA, $actorA);
        $connectionB = $this->pendingConnection($businessB, $actorB);

        $payloadA = $this->signer()->verify($this->signer()->issue($connectionA, $actorA));

        // A's nonce claimed for B's Business: no row matches.
        $this->assertFalse($this->signer()->consume(array_merge($payloadA, ['b' => (int) $businessB->id])));
        // B's user claiming A's attempt.
        $this->assertFalse($this->signer()->consume(array_merge($payloadA, ['u' => $actorB])));
        $this->assertNull($connectionB->fresh()->oauth_state_nonce);
        $this->assertNotNull($connectionA->fresh()->oauth_state_nonce, 'nothing was consumed');

        $this->assertTrue($this->signer()->consume($payloadA));
    }

    public function test_a_google_state_never_verifies_as_meta_and_a_meta_state_never_verifies_as_google(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;

        $google = BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::GoogleAds,
            'state' => GoogleConnectionState::Pending,
        ]);
        $googleSigner = app(GoogleOAuthStateSigner::class);
        $googleState = $googleSigner->issue($google);
        $this->assertNotNull($googleSigner->verify($googleState), 'control: Google state verifies in Google');

        $metaState = $this->signer()->issue($this->pendingConnection($business, $actor), $actor);
        $this->assertNotNull($this->signer()->verify($metaState), 'control: Meta state verifies in Meta');

        $this->assertNull($this->signer()->verify($googleState), 'Google state must NOT verify as Meta');
        $this->assertNull($googleSigner->verify($metaState), 'Meta state must NOT verify as Google');

        // Even a Meta-shaped payload signed with the Google key scheme is refused by Meta.
        $key = (string) config('app.key');
        $googleKey = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        $this->assertNull($this->signer()->verify($this->resign($this->payloadOf($metaState), $googleKey)));

        // And a Google-shaped payload signed with the Meta key is refused by Google.
        $googlePayload = $this->payloadOf($googleState);
        $this->assertNull($googleSigner->verify($this->resign($googlePayload)));
    }
}
