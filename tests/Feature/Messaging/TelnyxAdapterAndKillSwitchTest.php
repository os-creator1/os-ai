<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessageDispatchStatus;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\DTO\OutboundMessageRequest;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\TelnyxMessagingAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.4 — T-MSG-31, 32, 41, 42.
 *
 * The real Telnyx adapter, exercised exclusively under Http::fake(). No live
 * Telnyx call, account, number, brand, campaign or rate is created anywhere
 * in this file, and every credential used is an obvious fixture string.
 */
class TelnyxAdapterAndKillSwitchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private function request(string $operationKey = 'op_adapter'): OutboundMessageRequest
    {
        return new OutboundMessageRequest(
            businessMessagingIdentityId: 1,
            businessMessagingNumberId: 1,
            messagingProfileId: 'mp_fixture',
            fromNumber: '+14155550100',
            toNumber: '+14155550200',
            body: 'hello',
            mediaUrls: [],
            operationKey: $operationKey,
        );
    }

    // ---------------------------------------------------------------
    // T-MSG-31 / T-MSG-32 — the two independent activation gates
    // ---------------------------------------------------------------

    public function test_the_kill_switch_alone_prevents_construction_even_with_complete_credentials(): void
    {
        Http::fake();

        config([
            'messaging.managed_messaging_enabled' => false,
            'services.telnyx.api_key' => 'fixture_api_key',
            'services.telnyx.webhook_public_key' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);

        try {
            new TelnyxMessagingAdapter();
            $this->fail('The adapter must refuse to construct while the kill switch is off.');
        } catch (MessagingProviderNotConfiguredException $e) {
            $this->assertStringContainsString('messaging.managed_messaging_enabled', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_each_missing_credential_key_prevents_construction(): void
    {
        Http::fake();

        foreach (['services.telnyx.api_key', 'services.telnyx.webhook_public_key'] as $missingKey) {
            $this->enableManagedMessaging();
            config([$missingKey => '']);

            try {
                new TelnyxMessagingAdapter();
                $this->fail("The adapter must refuse to construct without [{$missingKey}].");
            } catch (MessagingProviderNotConfiguredException $e) {
                // The message names the KEY, never a value.
                $this->assertStringContainsString($missingKey, $e->getMessage());
                $this->assertStringNotContainsString('fixture_api_key', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_no_http_call_is_made_across_adapter_operations_while_disabled(): void
    {
        Http::fake();
        config(['messaging.managed_messaging_enabled' => false]);

        foreach (range(1, 3) as $attempt) {
            try {
                (new TelnyxMessagingAdapter())->send($this->request("op_{$attempt}"));
            } catch (MessagingProviderNotConfiguredException) {
                // expected — construction fails before any request is built
            }
        }

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Request shape, under Http::fake() only
    // ---------------------------------------------------------------

    public function test_an_accepted_send_uses_the_resolved_profile_and_number(): void
    {
        $this->enableManagedMessaging();
        Http::fake([
            'api.telnyx.com/*' => Http::response(['data' => ['id' => 'pm_real_shape']], 200),
        ]);

        $result = (new TelnyxMessagingAdapter())->send($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame(MessageDispatchStatus::Accepted, $result->status);
        $this->assertSame('pm_real_shape', $result->providerMessageId);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/v2/messages')
                && $body['from'] === '+14155550100'
                && $body['to'] === '+14155550200'
                && $body['messaging_profile_id'] === 'mp_fixture';
        });
    }

    public function test_a_2xx_without_a_correlatable_id_is_not_treated_as_accepted(): void
    {
        $this->enableManagedMessaging();
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => []], 200)]);

        $result = (new TelnyxMessagingAdapter())->send($this->request());

        $this->assertFalse($result->accepted, 'A 2xx we cannot correlate is not a confirmed acceptance.');
        $this->assertSame(ProviderErrorCategory::Unknown, $result->errorCategory);
    }

    public function test_provider_failures_are_categorized_and_never_accepted(): void
    {
        $this->enableManagedMessaging();

        $expectations = [
            401 => ProviderErrorCategory::Configuration,
            403 => ProviderErrorCategory::Configuration,
            422 => ProviderErrorCategory::Terminal,
            429 => ProviderErrorCategory::Retryable,
            500 => ProviderErrorCategory::Retryable,
        ];

        // A closure stub is evaluated per request, so each iteration really
        // gets its own status — re-registering an array stub inside the loop
        // would keep resolving to the first one registered.
        // By reference: an arrow function would capture the status by value
        // at registration and answer every request with the same one.
        $currentStatus = 500;
        Http::fake(function () use (&$currentStatus) {
            return Http::response(['errors' => []], $currentStatus);
        });

        foreach ($expectations as $status => $expected) {
            $currentStatus = $status;

            $result = (new TelnyxMessagingAdapter())->send($this->request("op_{$status}"));

            $this->assertFalse($result->accepted, "HTTP {$status} must never be accepted.");
            $this->assertSame($expected, $result->errorCategory, "HTTP {$status} miscategorized.");
        }
    }

    public function test_a_transport_failure_is_retryable_and_never_accepted(): void
    {
        $this->enableManagedMessaging();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $result = (new TelnyxMessagingAdapter())->send($this->request());

        $this->assertFalse($result->accepted, 'An ambiguous timeout must never be reported as sent.');
        $this->assertSame(ProviderErrorCategory::Retryable, $result->errorCategory);
        $this->assertNull($result->providerMessageId);
    }

    public function test_no_result_carries_the_raw_provider_body_or_a_credential(): void
    {
        $this->enableManagedMessaging();
        Http::fake([
            'api.telnyx.com/*' => Http::response([
                'data' => ['id' => 'pm_clean'],
                'secret_echo' => 'fixture_api_key',
            ], 200),
        ]);

        $result = (new TelnyxMessagingAdapter())->send($this->request());
        $encoded = (string) json_encode($result);

        $this->assertStringNotContainsString('fixture_api_key', $encoded);
        $this->assertStringNotContainsString('secret_echo', $encoded);
    }

    // ---------------------------------------------------------------
    // Signature verification returns false rather than throwing
    // ---------------------------------------------------------------

    public function test_signature_verification_returns_false_for_missing_or_malformed_material(): void
    {
        $this->enableManagedMessaging();
        Http::fake();
        $adapter = new TelnyxMessagingAdapter();

        $this->assertFalse($adapter->verifyInboundSignature('{}', []));
        $this->assertFalse($adapter->verifyInboundSignature('{}', ['telnyx-signature-ed25519' => 'abc']));
        $this->assertFalse($adapter->verifyInboundSignature('{}', [
            'telnyx-signature-ed25519' => 'not-base64!!',
            'telnyx-timestamp' => (string) time(),
        ]));
        // A timestamp far outside the skew window is refused even if the
        // signature were otherwise well-formed.
        $this->assertFalse($adapter->verifyInboundSignature('{}', [
            'telnyx-signature-ed25519' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
            'telnyx-timestamp' => (string) (time() - 86400),
        ]));

        Http::assertNothingSent();
    }

    public function test_a_genuine_signature_verifies_and_a_tampered_body_does_not(): void
    {
        Http::fake();

        // A throwaway keypair generated in-test — never a real credential.
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'messaging.managed_messaging_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key',
            'services.telnyx.webhook_public_key' => base64_encode($publicKey),
        ]);

        $adapter = new TelnyxMessagingAdapter();
        $timestamp = (string) time();
        $body = '{"data":{"event_type":"message.received"}}';
        $signature = base64_encode(sodium_crypto_sign_detached($timestamp . '|' . $body, $secretKey));

        $headers = [
            'telnyx-signature-ed25519' => $signature,
            'telnyx-timestamp' => $timestamp,
        ];

        $this->assertTrue($adapter->verifyInboundSignature($body, $headers));
        $this->assertFalse($adapter->verifyInboundSignature($body . 'tampered', $headers));
    }

    // ---------------------------------------------------------------
    // Payload parsing carries BOTH attribution signals
    // ---------------------------------------------------------------

    public function test_parsing_populates_both_independent_attribution_signals(): void
    {
        $this->enableManagedMessaging();
        Http::fake();

        $event = (new TelnyxMessagingAdapter())->parseInboundWebhook(json_encode([
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'pm_parsed',
                    'messaging_profile_id' => 'mp_parsed',
                    'text' => 'hello there',
                    'from' => ['phone_number' => '+14155550300'],
                    'to' => [['phone_number' => '+14155550400', 'status' => 'delivered']],
                ],
            ],
        ]));

        $this->assertSame(InboundWebhookEventKind::MessageReceived, $event->kind);
        $this->assertSame('mp_parsed', $event->messagingProfileId);
        $this->assertSame('+14155550400', $event->destinationNumber);
        $this->assertSame('+14155550300', $event->fromNumber);
        $this->assertSame('pm_parsed', $event->providerMessageId);
        $this->assertSame('hello there', $event->body);
    }

    public function test_a_finalized_payload_parses_as_a_delivery_status_event(): void
    {
        $this->enableManagedMessaging();
        Http::fake();

        $event = (new TelnyxMessagingAdapter())->parseInboundWebhook(json_encode([
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => 'pm_final',
                    'messaging_profile_id' => 'mp_final',
                    'to' => [['phone_number' => '+14155550500', 'status' => 'delivered']],
                ],
            ],
        ]));

        $this->assertSame(InboundWebhookEventKind::DeliveryStatus, $event->kind);
        $this->assertSame('delivered', $event->deliveryStatus);
        $this->assertSame('pm_final', $event->providerMessageId);
    }

    public function test_an_unparseable_body_throws_rather_than_guessing(): void
    {
        $this->enableManagedMessaging();
        Http::fake();

        $this->expectException(\App\Library\Messaging\Exceptions\MessagingIdentityUnresolvedException::class);
        (new TelnyxMessagingAdapter())->parseInboundWebhook('not json at all');
    }

    public function test_the_adapter_interface_exposes_no_slice_four_methods(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\App\Library\Messaging\Contracts\MessagingProviderAdapter::class))->getMethods(),
        );
        sort($methods);

        $this->assertSame(['parseInboundWebhook', 'send', 'verifyInboundSignature'], $methods);

        foreach ($methods as $method) {
            $this->assertDoesNotMatchRegularExpression('/number|order|search|register|campaign|brand/i', $method);
        }
    }
}
