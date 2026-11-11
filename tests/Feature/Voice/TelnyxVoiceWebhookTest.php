<?php

namespace Tests\Feature\Voice;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\BusinessVoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

class TelnyxVoiceWebhookTest extends TestCase
{
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private string $secret;
    private BusinessVoiceCall $voiceCall;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

        [$business, , $number] = $this->managedBusiness();
        $this->voiceCall = BusinessVoiceCall::create([
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'telnyx_call_control_id' => 'v3:cc-1',
            'to_hash' => str_repeat('a', 64),
            'status' => 'initiated',
        ]);
    }

    private function send(string $eventId, string $type, array $payload = [], bool $validSignature = true, string $callId = 'v3:cc-1', ?string $occurredAt = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => $occurredAt, 'payload' => $payload + ['call_control_id' => $callId]]]);
        $ts = (string) time();
        $sig = $validSignature
            ? base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $this->secret))
            : base64_encode(str_repeat('x', 64));

        return $this->call('POST', route('webhooks.telnyx_voice'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_an_invalid_signature_is_refused_and_changes_nothing(): void
    {
        $this->send('evt-1', 'call.answered', [], false)->assertForbidden();

        $this->assertSame('initiated', $this->voiceCall->fresh()->status);
        $this->assertSame(0, \DB::table('business_voice_call_events')->count());
    }

    public function test_events_move_the_call_forward_and_record_duration(): void
    {
        // start_time is when the call BEGAN (10:00:00); it must not be mistaken for the answer moment (10:00:08).
        $this->send('evt-a', 'call.answered', ['start_time' => '2026-11-12T10:00:00Z'], true, 'v3:cc-1', '2026-11-12T10:00:08Z')->assertOk()->assertJson(['status' => 'applied']);
        $this->send('evt-b', 'call.hangup', ['end_time' => '2026-11-12T10:00:42Z', 'hangup_cause' => 'normal_clearing'])->assertOk()->assertJson(['status' => 'applied']);

        $call = $this->voiceCall->fresh();
        $this->assertSame('ended', $call->status);
        $this->assertSame(34, $call->duration_seconds);
        $this->assertSame('normal_clearing', $call->hangup_cause);
    }

    public function test_a_redelivered_event_is_a_no_op(): void
    {
        $this->send('evt-dup', 'call.answered', ['start_time' => '2026-11-12T10:00:00Z'])->assertJson(['status' => 'applied']);
        $answeredAt = $this->voiceCall->fresh()->answered_at;

        $this->send('evt-dup', 'call.answered', ['start_time' => '2026-11-12T11:00:00Z'])->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertEquals($answeredAt, $this->voiceCall->fresh()->answered_at);
        $this->assertSame(1, \DB::table('business_voice_call_events')->count());
    }

    public function test_a_late_earlier_event_never_regresses_the_call(): void
    {
        $this->send('evt-1', 'call.hangup', ['end_time' => '2026-11-12T10:00:42Z']);
        // Applied only as a timestamp backfill: the status stays ended.
        $this->send('evt-2', 'call.answered', ['start_time' => '2026-11-12T10:00:00Z'])->assertJson(['status' => 'applied']);

        $this->assertSame('ended', $this->voiceCall->fresh()->status);
    }

    private function awaitingId(string $token): void
    {
        $this->voiceCall->forceFill([
            'telnyx_call_control_id' => null, 'status' => 'requested', 'client_state_hash' => hash('sha256', $token),
        ])->save();
    }

    public function test_an_event_that_outruns_the_saved_call_id_is_matched_by_its_token(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->awaitingId($token);

        $this->send('evt-early', 'call.initiated', ['client_state' => base64_encode($token)])
            ->assertOk()->assertJson(['status' => 'applied']);

        $call = $this->voiceCall->fresh();
        $this->assertSame('initiated', $call->status);
        $this->assertSame('v3:cc-1', $call->telnyx_call_control_id);
    }

    /** @return array<string, array{0: ?string}> */
    public static function badStates(): array
    {
        return [
            'row id (the old predictable form)' => [base64_encode('1')],
            'wrong token' => [base64_encode(str_repeat('a', 64))],
            'not base64' => ['!!!not-base64!!!'],
            'empty' => [''],
            'missing' => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badStates')]
    public function test_a_missing_or_wrong_token_never_associates_a_call(?string $state): void
    {
        $this->awaitingId(bin2hex(random_bytes(32)));
        $payload = $state === null ? [] : ['client_state' => $state];

        $this->send('evt-bad', 'call.initiated', $payload)->assertOk()->assertJson(['status' => 'ignored']);

        $call = $this->voiceCall->fresh();
        $this->assertNull($call->telnyx_call_control_id);
        $this->assertSame('requested', $call->status);
    }

    public function test_a_token_cannot_re_adopt_a_row_that_already_has_a_call_id(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->voiceCall->forceFill(['client_state_hash' => hash('sha256', $token)])->save(); // keeps 'v3:cc-1'

        $this->send('evt-other', 'call.answered', ['client_state' => base64_encode($token)], true, 'v3:another-call')
            ->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame('v3:cc-1', $this->voiceCall->fresh()->telnyx_call_control_id);
    }

    public function test_a_late_answer_after_hangup_backfills_answer_time_and_duration_without_regressing(): void
    {
        $this->send('evt-h', 'call.hangup', ['end_time' => '2026-11-12T10:00:42Z'])->assertJson(['status' => 'applied']);
        $this->assertNull($this->voiceCall->fresh()->duration_seconds);

        $this->send('evt-a', 'call.answered', [], true, 'v3:cc-1', '2026-11-12T10:00:08Z')->assertJson(['status' => 'applied']);

        $call = $this->voiceCall->fresh();
        $this->assertSame('ended', $call->status);
        $this->assertSame(34, $call->duration_seconds);
        $this->assertSame('2026-11-12 10:00:08', $call->answered_at->utc()->format('Y-m-d H:i:s'));

        // Redelivery is a no-op and a second answer cannot overwrite the recorded time.
        $this->send('evt-a', 'call.answered', [], true, 'v3:cc-1', '2026-11-12T10:00:30Z')->assertJson(['status' => 'duplicate']);
        $this->send('evt-a2', 'call.answered', [], true, 'v3:cc-1', '2026-11-12T10:00:30Z')->assertJson(['status' => 'ignored']);
        $this->assertSame(34, $this->voiceCall->fresh()->duration_seconds);
    }

    public function test_an_unknown_call_is_ignored_and_creates_nothing(): void
    {
        $this->send('evt-x', 'call.answered', [], true, 'v3:someone-else')->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, BusinessVoiceCall::count());
    }

    public function test_the_csrf_exemption_is_this_one_exact_path(): void
    {
        $except = (new \ReflectionClass(VerifyCsrfToken::class))->getDefaultProperties()['except'];
        $this->assertContains('webhooks/telnyx-voice', $except);
        $this->assertSame([], array_values(array_intersect($except, ['*', 'webhooks/*', 'webhooks*'])));
    }
}
