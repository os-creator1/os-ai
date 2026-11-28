<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyOutreach\AgencyByoConnectionRechecker;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\AgencyOutreach\AgencyOutreachReadiness;
use App\Library\AgencyOutreach\OutreachMessageSender;
use App\Models\AgencyProspectingChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Authentication-vs-restriction classification, bounded readiness recovery and `delivery_unconfirmed`
 * handling for an agency's OWN Telnyx account. Every Telnyx call is mocked; nothing is sent.
 */
class AgencyByoTelnyxReadinessRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;
    use BuildsAgencyByoChannels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    /** Telnyx mock: /messages answers $messageStatus (with $errors), the read-only checks answer $profileStatus. */
    private function telnyx(int $messageStatus = 200, array $errors = [], int $profileStatus = 200): void
    {
        Http::swap(new Factory());
        $seq = 0;

        Http::fake([
            'api.telnyx.com/v2/messages' => function () use (&$seq, $messageStatus, $errors) {
                $seq++;

                return $messageStatus === 200
                    ? Http::response(['data' => ['id' => 'tx-msg-' . $seq]], 200)
                    : Http::response(['errors' => $errors], $messageStatus);
            },
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response($profileStatus === 200 ? ['data' => ['id' => 'p']] : ['errors' => [['code' => '10009']]], $profileStatus),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'n1', 'status' => 'active']]], 200),
        ]);
    }

    private function profileChecks(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/messaging_profiles/'))->count();
    }

    private function nonGetRequests(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count();
    }

    private function send(object $business, string $key)
    {
        return app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', $key);
    }

    // ------------------------------------------------------------------ classification

    public function test_a_confirmed_authentication_rejection_blocks_sending_and_is_persisted(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $this->telnyx(401, [['code' => '10009']], 401);

        $this->assertSame('failed', $this->send($business, 'auth-1')->status);
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);

        // The re-verification also fails (key really rejected), so the block stays and nothing is sent.
        $second = $this->send($business, 'auth-2');
        $this->assertSame(['blocked', 'byo_invalid_api_key'], [$second->status, $second->reason]);
        $this->assertCount(1, $this->telnyxMessageRequests());
    }

    public function test_an_http_403_with_the_authentication_failed_code_is_a_bad_key_but_other_403s_are_not(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $this->telnyx(403, [['code' => '10009', 'detail' => 'Authentication failed']], 401);
        $this->send($business, 'auth-403');
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);

        [, $business2, $workspace2] = $this->agency('Other Co');
        $channel2 = $this->byoChannel($workspace2, ['default' => true]);
        $this->telnyx(403, [['code' => '40300', 'detail' => 'Not authorized to send from this number']]);
        $this->send($business2, 'perm-403');

        $this->assertSame('sender_restricted', $channel2->fresh()->last_error_code);
        $this->assertNotSame('invalid_api_key', $channel2->fresh()->last_error_code);
    }

    public function test_a_provider_restriction_stays_enforced_and_is_not_retried_in_a_loop(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $this->telnyx(403, [['code' => '40300']]);

        $this->send($business, 'restricted-1');
        $second = $this->send($business, 'restricted-2');
        $third = $this->send($business, 'restricted-3');

        $this->assertSame(['blocked', 'byo_sender_restricted'], [$second->status, $second->reason]);
        $this->assertSame('blocked', $third->status);
        $this->assertCount(1, $this->telnyxMessageRequests(), 'A prohibited send is not attempted again.');

        // Checking the key cannot lift a restriction.
        $result = app(AgencyByoConnectionRechecker::class)->recheck($channel->fresh(), true);
        $this->assertSame('not_needed', $result['status']);
        $this->assertSame('sender_restricted', $channel->fresh()->last_error_code);
        $this->assertSame(0, $this->profileChecks());

        // After the cool-down exactly ONE new attempt is allowed; a still-present restriction re-blocks.
        $this->travel(AgencyByoSender::RESTRICTION_COOLDOWN_SECONDS + 60)->seconds();
        $this->send($business, 'restricted-4');
        $this->send($business, 'restricted-5');
        $this->assertCount(2, $this->telnyxMessageRequests());
        $this->assertSame('sender_restricted', $channel->fresh()->last_error_code);
    }

    public function test_timeouts_429_and_5xx_never_persist_an_authentication_or_restriction_state(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);

        foreach ([429, 500, 503] as $i => $status) {
            $this->telnyx($status, [['code' => '99999']]);
            $this->send($business, 'transient-' . $i);
            $this->assertNull($channel->fresh()->last_error_code, "HTTP {$status}");
        }

        Http::swap(new Factory());
        Http::fake(['api.telnyx.com/v2/messages' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->send($business, 'transient-timeout');
        $this->assertNull($channel->fresh()->last_error_code);
    }

    // ------------------------------------------------------------------ recovery

    public function test_a_valid_key_recovers_from_a_stale_flag_without_replacing_credentials_or_sending_anything(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true, 'key' => 'saved-key']);
        $channel->forceFill(['last_error_code' => 'invalid_api_key', 'last_error_at' => now()->subDay()])->save();
        $rawKey = $channel->sendingServer->getRawOriginal('api_key');
        $this->telnyx();

        $state = AgencyByoSender::sendReadinessRecovering($channel);

        $this->assertTrue($state['ok']);
        $fresh = $channel->fresh();
        $this->assertNull($fresh->last_error_code);
        $this->assertSame($rawKey, $fresh->sendingServer->getRawOriginal('api_key'));
        $this->assertSame($channel->sender_number, $fresh->sender_number);
        $this->assertSame($channel->sending_server_id, $fresh->sending_server_id);
        $this->assertCount(0, $this->telnyxMessageRequests(), 'Verification never sends an SMS.');
        $this->assertSame(0, $this->nonGetRequests());
    }

    public function test_failed_reverification_keeps_the_block_and_reports_the_real_reason(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $channel->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->telnyx(200, [], 401);

        $result = app(AgencyByoConnectionRechecker::class)->recheck($channel, true);

        $this->assertSame('still_blocked', $result['status']);
        $this->assertStringContainsString('rejected this access key', (string) $result['message']);
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);
        $this->assertFalse(AgencyByoSender::sendReadiness($channel->fresh())['ok']);

        // A transient outage is inconclusive: neither cleared nor re-declared.
        Cache::flush();
        Http::swap(new Factory());
        Http::fake(['api.telnyx.com/*' => Http::response([], 503)]);
        $this->assertSame('inconclusive', app(AgencyByoConnectionRechecker::class)->recheck($channel->fresh(), true)['status']);
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);
    }

    public function test_repeated_and_concurrent_recovery_attempts_cannot_flood_telnyx(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $channel->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->telnyx(200, [], 401);

        for ($i = 0; $i < 10; $i++) {
            AgencyByoSender::sendReadinessRecovering($channel->fresh());
            app(AgencyByoConnectionRechecker::class)->recheck($channel->fresh(), true);
        }

        $this->assertSame(1, $this->profileChecks(), 'Rate-limited: one verification inside the interval.');

        // Another worker already holds the verification lock: this one does not call Telnyx at all.
        Cache::flush();
        $held = Cache::lock('byo-telnyx-recheck-lock:' . $channel->id, 60);
        $this->assertTrue($held->get());
        $before = $this->profileChecks();
        $this->assertSame('in_progress', app(AgencyByoConnectionRechecker::class)->recheck($channel->fresh(), true)['status']);
        $this->assertSame($before, $this->profileChecks());
        $held->release();
    }

    public function test_recovery_never_duplicates_accepted_or_uncertain_sends(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);

        $this->telnyx();
        $accepted = $this->send($business, 'dup-accepted');
        $this->telnyx(503);
        $uncertain = $this->send($business, 'dup-uncertain');
        $this->assertSame('failed', $uncertain->status);

        $channel->fresh()->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->telnyx();
        AgencyByoSender::sendReadinessRecovering($channel->fresh());
        $this->assertNull($channel->fresh()->last_error_code);

        $this->send($business, 'dup-accepted');
        $this->send($business, 'dup-uncertain');

        $this->assertCount(0, $this->telnyxMessageRequests(), 'Same keys are answered from their recorded operations; nothing is resent.');
        $this->assertSame(1, DB::table('business_messaging_operations')->where('operation_key', 'dup-accepted')->count());
        $this->assertSame(1, DB::table('business_messaging_operations')->where('operation_key', 'dup-uncertain')->count());
    }

    // ------------------------------------------------------------------ delivery_unconfirmed

    private function acceptedOperation(): array
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $this->telnyx();
        $this->send($business, 'dlr-op');

        return [$channel, (string) DB::table('business_messaging_operations')->where('operation_key', 'dlr-op')->value('provider_message_id')];
    }

    private function op(): object
    {
        return DB::table('business_messaging_operations')->where('operation_key', 'dlr-op')->first();
    }

    public function test_a_signed_delivery_unconfirmed_event_is_acknowledged_and_stays_uncertain(): void
    {
        [$channel, $id] = $this->acceptedOperation();

        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivery_unconfirmed'))->assertOk()->assertJson(['status' => 'accepted']);

        $op = $this->op();
        $this->assertSame('accepted', $op->status, 'Neither delivered nor failed.');
        $this->assertNotNull($op->delivery_unconfirmed_at);
        $this->assertNull($op->error_category);
        $this->assertCount(1, $this->telnyxMessageRequests(), 'No automatic resend.');
        $this->assertNull($channel->fresh()->last_error_code, 'Delivery events never touch sender readiness.');

        // Telnyx repeats it: idempotent.
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivery_unconfirmed'))->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertCount(1, $this->telnyxMessageRequests());
    }

    public function test_confirmed_delivery_cannot_regress_to_unconfirmed_and_a_late_delivery_still_settles(): void
    {
        [$channel, $id] = $this->acceptedOperation();

        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivery_unconfirmed'))->assertOk();
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivered'))->assertOk()->assertJson(['status' => 'accepted']);
        $this->assertSame('delivered', $this->op()->status);

        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivery_unconfirmed'))->assertOk();
        $this->assertSame('delivered', $this->op()->status);
    }

    public function test_unsupported_but_valid_statuses_are_acknowledged_without_changing_state(): void
    {
        [$channel, $id] = $this->acceptedOperation();

        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'some_future_status'))->assertOk()->assertJson(['status' => 'ignored']);
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'queued'))->assertOk();
        $this->assertSame('accepted', $this->op()->status);
        $this->assertNull($this->op()->delivery_unconfirmed_at);
    }

    public function test_bad_signatures_malformed_payloads_and_foreign_channels_are_still_refused(): void
    {
        [$channel, $id] = $this->acceptedOperation();
        [, , $otherWorkspace] = $this->agency('Other Co');
        $other = $this->byoChannel($otherWorkspace, ['default' => true]);

        // Wrong signing key.
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $id, 'delivery_unconfirmed'), $this->byoSecretKeys[(int) $other->id])->assertStatus(403);
        // Missing status: malformed.
        $bad = $this->deliveryPayload($channel, $id, 'x');
        unset($bad['data']['payload']['to'][0]['status']);
        $this->signedWebhook($channel, $bad)->assertStatus(400);
        // Another agency's valid channel naming my message id moves nothing.
        $this->signedWebhook($other, $this->deliveryPayload($other, $id, 'delivery_unconfirmed'));
        $this->assertNull($this->op()->delivery_unconfirmed_at);
    }

    // ------------------------------------------------------------------ readiness indicators

    public function test_readiness_reports_current_health_not_historical_verification(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        // Historically verified AND webhook working — but the key is now rejected.
        $channel->forceFill([
            'webhook_verified_at' => now()->subDays(3), 'sms_capability_confirmed_at' => now()->subDays(3),
            'last_error_code' => 'invalid_api_key',
        ])->save();
        $this->telnyx(200, [], 401);

        $items = collect(app(AgencyOutreachReadiness::class)->forWorkspace($workspace->fresh())->items())->keyBy('key');

        $this->assertTrue($items['number']['ok'], 'The sender itself is fine.');
        $this->assertFalse($items['verification']['ok']);
        $this->assertStringContainsString('Telnyx rejected the saved access key', (string) $items['verification']['reason']);

        // Once the stale flag is rechecked clean, it is green again.
        Cache::flush();
        $this->telnyx();
        $items = collect(app(AgencyOutreachReadiness::class)->forWorkspace($workspace->fresh())->items())->keyBy('key');
        $this->assertTrue($items['verification']['ok']);
    }
}
