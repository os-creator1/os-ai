<?php

namespace Tests\Feature\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laravel skips CSRF verification while running unit tests, so every other webhook test passes without ever proving the
 * exemption. Here CSRF is switched ON (the application reports a non-testing environment) and the real route is called
 * the way Telnyx calls it: no session, no token.
 */
class PortInWebhookCsrfTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->previousEnv = $this->app['env'];
        $this->app['env'] = 'production-like-for-csrf';
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->previousEnv;
        parent::tearDown();
    }

    private function telnyx(string $signature): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['data' => ['id' => 'evt-csrf', 'event_type' => 'porting_order.status_changed', 'payload' => ['id' => 'unknown-order', 'status' => ['value' => 'in-process']]]]);
        $ts = (string) time();
        $sig = $signature === 'valid' ? base64_encode(sodium_crypto_sign_detached($ts.'|'.$body, $this->secret)) : base64_encode(str_repeat('x', 64));

        return $this->call('POST', route('webhooks.telnyx_porting'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_csrf_is_really_enforced_in_this_test_so_the_exemption_is_proven(): void
    {
        $this->call('POST', '/login')->assertStatus(419);
    }

    public function test_a_signed_event_without_a_csrf_token_is_accepted(): void
    {
        $this->assertNotSame(419, $this->telnyx('valid')->getStatusCode(), 'The signed Telnyx webhook must not be stopped by CSRF.');
        $this->telnyx('valid')->assertSuccessful();
    }

    public function test_a_bad_signature_is_still_refused_by_the_signature_check_not_the_exemption(): void
    {
        $this->telnyx('invalid')->assertForbidden();
    }

    public function test_the_exemption_is_this_one_exact_path_and_no_wildcard(): void
    {
        $except = (new \ReflectionClass(\App\Http\Middleware\VerifyCsrfToken::class))->getDefaultProperties()['except'];
        $this->assertContains('webhooks/telnyx-porting', $except);
        $this->assertSame([], array_values(array_intersect($except, ['*', 'webhooks/*', 'webhooks*'])), 'No blanket webhook exemption.');
    }
}
