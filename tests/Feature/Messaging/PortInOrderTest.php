<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\PortIn\PortInOrderManager;
use App\Library\Messaging\PortIn\TelnyxPortabilityChecker;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** No carrier is contacted: every provider event is a locally signed, synthetic Telnyx webhook. */
class PortInOrderTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '+17205028306';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    }

    private function tenant(string $owner): array
    {
        if (! User::query()->where('is_admin', true)->exists()) {
            User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa'.uniqid().'@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        }
        $ctx = app(LuminaAuraProvisioner::class)->provision($owner, 'a-long-test-password-1', [])['context'];

        return [$ctx->business, $ctx->location];
    }

    private function withIdentity(Business $business): void
    {
        app(BusinessMessagingIdentityResolver::class)->create($business, 'profile-'.$business->id, null, \App\Enums\Messaging\MessagingProvider::Telnyx, BusinessMessagingIdentityStatus::Active);
    }

    private function webhook(string $eventId, string $orderId, string $status, bool $sign = true, ?string $type = 'porting_order.status_changed')
    {
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'payload' => ['id' => $orderId, 'status' => ['value' => $status]]]]);
        $ts = (string) time();
        $sig = $sign ? base64_encode(sodium_crypto_sign_detached($ts.'|'.$body, $this->secret)) : base64_encode(str_repeat('x', 64));

        return $this->call('POST', route('webhooks.telnyx_porting'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_tracking_refuses_a_number_another_tenant_holds_or_is_already_porting(): void
    {
        [$a] = $this->tenant('a@example.test');
        [$b] = $this->tenant('b@example.test');
        $orders = app(PortInOrderManager::class);

        $order = $orders->track($a, self::NUMBER);
        $this->assertSame('draft', $order['status']);
        $this->assertCount(8, json_decode($order['checklist'], true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already has a live port order');
        $orders->track($b, self::NUMBER);
    }

    public function test_a_signed_ported_event_attaches_the_number_to_the_right_business_and_location_once(): void
    {
        [$a, $locA] = $this->tenant('a@example.test');
        [$b] = $this->tenant('b@example.test');
        $this->withIdentity($a);
        $this->withIdentity($b);
        $orders = app(PortInOrderManager::class);
        $order = $orders->track($a, self::NUMBER, null, (int) $locA->id, 'po_123');

        $this->webhook('evt-1', 'po_123', 'in-process')->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame('in_process', DB::table('messaging_port_in_orders')->value('status'));
        $this->assertSame(0, DB::table('business_messaging_numbers')->count(), 'Nothing is attached before the carrier says ported.');

        $this->webhook('evt-2', 'po_123', 'ported')->assertOk();
        $this->assertSame('assigned', DB::table('messaging_port_in_orders')->value('status'));

        $number = DB::table('business_messaging_numbers')->sole();
        $this->assertSame(self::NUMBER, $number->phone_number);
        $this->assertSame('active', $number->status);
        $identityBusiness = DB::table('business_messaging_identities')->where('id', $number->business_messaging_identity_id)->value('business_id');
        $this->assertSame((int) $a->id, (int) $identityBusiness, 'Assigned to the ordering Business, never the other tenant.');
        $this->assertSame(1, DB::table('business_messaging_number_locations')->where('business_messaging_number_id', $number->id)->where('business_location_id', $locA->id)->count());

        // Inbound attribution now resolves the number to Business A only.
        $resolved = app(BusinessMessagingIdentityResolver::class)->resolveByPhoneNumber(self::NUMBER);
        $this->assertSame((int) $a->id, (int) $resolved->business_id);

        // Replays and late, older events change nothing.
        $this->webhook('evt-2', 'po_123', 'ported')->assertOk()->assertJson(['status' => 'duplicate']);
        $this->webhook('evt-3', 'po_123', 'in-process')->assertOk();
        $this->assertSame('assigned', DB::table('messaging_port_in_orders')->value('status'));
        $this->assertSame(1, DB::table('business_messaging_numbers')->count());
    }

    public function test_bad_or_missing_signature_and_malformed_bodies_change_nothing(): void
    {
        [$a] = $this->tenant('a@example.test');
        $this->withIdentity($a);
        app(PortInOrderManager::class)->track($a, self::NUMBER, null, null, 'po_9');

        $this->webhook('evt-x', 'po_9', 'ported', sign: false)->assertStatus(403);
        $this->assertSame('submitted', DB::table('messaging_port_in_orders')->value('status'));
        $this->assertSame(0, DB::table('business_messaging_numbers')->count());
        $this->assertSame(0, DB::table('messaging_port_in_events')->count());

        $this->webhook('', 'po_9', 'ported')->assertStatus(400);
        $this->webhook('evt-y', 'unknown_order', 'ported')->assertOk()->assertJson(['status' => 'unknown_order']);
        $this->webhook('evt-z', 'po_9', 'ported', true, 'porting_order.new_comment')->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(0, DB::table('business_messaging_numbers')->count());

        config(['services.telnyx.webhook_public_key' => '']);
        $this->webhook('evt-q', 'po_9', 'ported')->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_ported_without_a_messaging_profile_waits_for_a_human_instead_of_guessing(): void
    {
        [$a] = $this->tenant('a@example.test');
        app(PortInOrderManager::class)->track($a, self::NUMBER, null, null, 'po_7');

        $this->webhook('evt-1', 'po_7', 'ported')->assertOk();
        $this->assertSame('ported', DB::table('messaging_port_in_orders')->value('status'));
        $this->assertSame(0, DB::table('business_messaging_numbers')->count());
    }

    public function test_portability_check_is_read_only_and_refuses_without_a_key(): void
    {
        config(['services.telnyx.api_key' => '']);
        try {
            app(TelnyxPortabilityChecker::class)->check([self::NUMBER]);
            $this->fail('Expected a refusal without TELNYX_API_KEY.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TELNYX_API_KEY', $e->getMessage());
        }

        config(['services.telnyx.api_key' => 'KEY_test']);
        Http::fake(['api.telnyx.com/v2/portability_checks' => Http::response(['data' => [['phone_number' => self::NUMBER, 'portable' => true, 'fast_portable' => false, 'messaging_capable' => true, 'not_portable_reason' => null]]])]);
        $result = app(TelnyxPortabilityChecker::class)->check(['(720) 502-8306']);

        $this->assertTrue($result[self::NUMBER]['portable']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/portability_checks'));
    }
}
