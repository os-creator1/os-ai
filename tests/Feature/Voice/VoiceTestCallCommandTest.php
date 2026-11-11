<?php

namespace Tests\Feature\Voice;

use App\Models\BusinessVoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

class VoiceTestCallCommandTest extends TestCase
{
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const DEST = '+14155552671';
    private const CONNECTION = 'conn_fixture_1';
    private const REF = 'num_fixture_1';

    private $business;
    private $number;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.voice.test_call_enabled' => true,
            'messaging.voice.connection_id' => self::CONNECTION,
            'messaging.voice.test_call_allowlist' => self::DEST,
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
        ]);
        [$this->business, , $this->number] = $this->managedBusiness();
        $this->number->forceFill(['provider_number_reference' => self::REF])->save();
    }

    private function fakeTelnyx(string $connectionId = self::CONNECTION, int $callStatus = 200): void
    {
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/*' => Http::response(['data' => [
                'id' => self::REF, 'phone_number' => $this->number->phone_number, 'status' => 'active', 'connection_id' => $connectionId,
            ]]),
            'api.telnyx.com/v2/calls' => $callStatus === 200
                ? Http::response(['data' => ['call_control_id' => 'v3:cc-1']], 200)
                : Http::response(['errors' => [['code' => '10010', 'title' => 'Forbidden']]], $callStatus),
        ]);
    }

    private function run_(?string $to = null): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('voice:test-call', ['business' => $this->business->id, 'to' => $to ?? self::DEST]);
    }

    private function assertNoCallRequest(): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_call_is_placed_from_the_owned_number_and_persisted_without_the_destination(): void
    {
        $this->fakeTelnyx();

        $this->run_()->assertExitCode(0);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && $r->url() === 'https://api.telnyx.com/v2/calls'
                && $r['connection_id'] === self::CONNECTION
                && $r['from'] === $this->number->phone_number
                && $r['to'] === self::DEST
                && $r['webhook_url'] === 'https://app.example.test/webhooks/telnyx-voice'
                && base64_decode($r['client_state']) === (string) BusinessVoiceCall::firstOrFail()->id
                && $r['time_limit_secs'] === 60 && $r['timeout_secs'] === 30
                && $r->hasHeader('Authorization');
        });

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('initiated', $call->status);
        $this->assertSame('v3:cc-1', $call->telnyx_call_control_id);
        $this->assertSame($this->business->id, $call->business_id);
        $this->assertSame($this->number->id, $call->business_messaging_number_id);
        $this->assertSame(BusinessVoiceCall::hashDestination(self::DEST), $call->to_hash);
        $this->assertStringNotContainsString('4155552671', json_encode($call->getAttributes()));
    }

    /** @return array<string, array{0: array<string,mixed>, 1: ?string}> */
    public static function refusals(): array
    {
        return [
            'not enabled' => [['messaging.voice.test_call_enabled' => false], null],
            'not live' => [['services.telnyx.mode' => 'sandbox'], null],
            'no connection id' => [['messaging.voice.connection_id' => ''], null],
            'no api key' => [['services.telnyx.api_key' => ''], null],
            'empty allowlist' => [['messaging.voice.test_call_allowlist' => ''], null],
            'destination not allowlisted' => [[], '+14155552672'],
            'webhook not https' => [['messaging.voice.webhook_url' => 'http://app.example.test/webhooks/telnyx-voice'], null],
            'prefix is not a match' => [['messaging.voice.test_call_allowlist' => '+1415555267'], null],
        ];
    }

    #[DataProvider('refusals')]
    public function test_unauthorized_execution_is_refused_before_any_request(array $config, ?string $to): void
    {
        $this->fakeTelnyx();
        config($config);

        $this->run_($to)->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    public function test_a_business_without_an_active_number_is_refused(): void
    {
        $this->fakeTelnyx();
        $other = $this->makeBusiness();

        $this->artisan('voice:test-call', ['business' => $other->id, 'to' => self::DEST])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_number_not_assigned_to_the_voice_connection_is_refused_without_dialing(): void
    {
        $this->fakeTelnyx('some_other_connection');

        $this->run_()->assertExitCode(1);

        $this->assertNoCallRequest();
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    public function test_a_telnyx_api_failure_is_recorded_as_failed_and_exits_non_zero(): void
    {
        $this->fakeTelnyx(self::CONNECTION, 403);

        $this->run_()->assertExitCode(1);

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('failed', $call->status);
        $this->assertSame('http_403', $call->failure_code);
        $this->assertNull($call->telnyx_call_control_id);
    }
}
