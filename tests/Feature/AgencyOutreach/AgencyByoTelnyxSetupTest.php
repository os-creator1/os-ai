<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyOutreach\AgencyOutreachReadiness;
use App\Library\Security\SealedSecret;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * Connecting, protecting, choosing and disconnecting the agency's own Telnyx account.
 * Telnyx is mocked throughout; nothing is sent, bought or billed.
 */
class AgencyByoTelnyxSetupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;
    use BuildsAgencyByoChannels;

    private function validWebhookKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    private function connectTelnyx(array $agency, array $overrides = [])
    {
        return $this->post(
            route('customer.workspaces.prospecting.channels.connect', [$agency['workspace']->uid, 'Telnyx']),
            array_merge(['sender_number' => '+12025552000', 'api_key' => 'KEY-plain-secret-123', 'c1' => 'profile-1', 'webhook_public_key' => $this->validWebhookKey()], $overrides),
        );
    }

    // ------------------------------------------------------------------ credential protection

    public function test_sealed_secrets_round_trip_and_legacy_plaintext_passes_through(): void
    {
        $sealed = SealedSecret::seal('super-secret');

        $this->assertStringStartsWith('enc:v1:', $sealed);
        $this->assertStringNotContainsString('super-secret', $sealed);
        $this->assertSame('super-secret', SealedSecret::open($sealed));
        $this->assertSame($sealed, SealedSecret::seal($sealed), 'Sealing is idempotent.');
        $this->assertSame('legacy-plain', SealedSecret::open('legacy-plain'), 'Old plaintext rows still read.');
        $this->assertNull(SealedSecret::open('enc:v1:tampered'), 'An unreadable value is null, never a throw or a leak.');
        $this->assertNull(SealedSecret::seal(null));
        $this->assertSame('', SealedSecret::seal(''));
    }

    public function test_the_model_reads_both_stored_formats_and_growth_admin_plaintext_writes_are_unchanged(): void
    {
        $base = ['name' => 'T', 'settings' => SendingServer::TYPE_TELNYX, 'status' => true, 'plain' => true, 'user_id' => 1];

        $legacy = SendingServer::create($base + ['api_key' => 'plain-growth-key', 'auth_token' => 'plain-token']);
        $sealed = SendingServer::create($base + ['api_key' => SealedSecret::seal('sealed-agency-key')]);

        $this->assertSame('plain-growth-key', $legacy->fresh()->api_key);
        $this->assertSame('plain-growth-key', DB::table('sending_servers')->where('id', $legacy->id)->value('api_key'), 'Existing code still stores plaintext.');
        $this->assertSame('plain-token', $legacy->fresh()->auth_token);
        $this->assertSame('sealed-agency-key', $sealed->fresh()->api_key);
        $this->assertStringStartsWith('enc:v1:', DB::table('sending_servers')->where('id', $sealed->id)->value('api_key'));

        // Re-saving an unrelated field never alters or corrupts either credential.
        $sealed->fresh()->update(['name' => 'Renamed']);
        $legacy->fresh()->update(['name' => 'Renamed']);
        $this->assertSame('sealed-agency-key', $sealed->fresh()->api_key);
        $this->assertSame('plain-growth-key', $legacy->fresh()->api_key);
    }

    public function test_connecting_stores_the_key_sealed_and_never_shows_it_again(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => ['id' => 'p']]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'n', 'status' => 'active']]])]);

        $response = $this->connectTelnyx($agency);

        $channel = AgencyProspectingChannel::where('workspace_id', $agency['workspace']->id)->sole();
        $response->assertRedirect(route('customer.workspaces.prospecting.channels.show', [$agency['workspace']->uid, $channel->uid]));

        $raw = DB::table('sending_servers')->where('id', $channel->sending_server_id)->value('api_key');
        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertStringNotContainsString('KEY-plain-secret-123', $raw);
        $this->assertSame('KEY-plain-secret-123', $channel->sendingServer->api_key, 'Server code can still use it.');
        $this->assertStringStartsWith('enc:v1:', DB::table('agency_prospecting_channels')->where('id', $channel->id)->value('webhook_public_key'));
        $this->assertNotNull($channel->credentials_verified_at);
        $this->assertFalse($channel->is_default_sender, 'Connecting never silently becomes the sender.');

        foreach ([
            route('customer.workspaces.prospecting.channels.show', [$agency['workspace']->uid, $channel->uid]),
            route('customer.workspaces.prospecting.channels.index', $agency['workspace']->uid),
            route('customer.workspaces.prospecting.phone-messaging', $agency['workspace']->uid),
        ] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertDontSee('KEY-plain-secret-123', false)->assertDontSee($raw, false);
        }

        $this->assertStringNotContainsString('KEY-plain-secret-123', json_encode($channel->fresh()->load('sendingServer')->toArray()) ?: '');
    }

    public function test_connection_is_refused_and_nothing_is_stored_for_each_provider_problem(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);

        $cases = [
            'rejected key' => [['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 401)], 'rejected this access key'],
            'unknown profile' => [['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 404)], 'Messaging profile ID was not found'],
            'provider down' => [['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 503)], 'could not be reached'],
            'number not in account' => [['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => []])], 'not a number in this Telnyx account'],
            'number not active' => [['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['status' => 'port-out-pending']]])], 'not active'],
        ];

        foreach ($cases as $name => [$fakes, $message]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake($fakes);

            $this->connectTelnyx($agency)->assertSessionHasErrors('api_key');
            $this->assertStringContainsString($message, session('errors')->first('api_key'), $name);
            $this->assertSame(0, AgencyProspectingChannel::count(), $name);
            $this->assertSame(0, SendingServer::where('settings', SendingServer::TYPE_TELNYX)->count(), $name . ': no credential left behind');
        }
    }

    public function test_an_invalid_webhook_key_a_second_owner_and_a_managed_number_are_all_refused(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $other = $this->outreachAgency('Beta');
        $this->authenticateAs($agency['customer']);
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['status' => 'active']]])]);

        $this->connectTelnyx($agency, ['webhook_public_key' => 'not-a-key'])->assertSessionHasErrors('webhook_public_key');

        // Already owned by another agency.
        $this->byoChannel($other['workspace'], ['sender' => '12025552000']);
        $this->connectTelnyx($agency)->assertSessionHasErrors('sender_number');

        // Already a number bought through MotionGrove.
        $managed = DB::table('business_messaging_numbers')->value('phone_number');
        $this->connectTelnyx($agency, ['sender_number' => $managed])->assertSessionHasErrors('sender_number');

        $this->assertSame(0, AgencyProspectingChannel::where('workspace_id', $agency['workspace']->id)->count());
    }

    public function test_replacing_credentials_is_verified_first_and_a_rejected_replacement_leaves_the_connection_untouched(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $channel = $this->byoChannel($agency['workspace'], ['key' => 'OLD-KEY', 'default' => false]);
        $before = DB::table('sending_servers')->where('id', $channel->sending_server_id)->value('api_key');
        $url = route('customer.workspaces.prospecting.channels.update', [$agency['workspace']->uid, $channel->uid]);

        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 401)]);
        $this->put($url, ['api_key' => 'NEW-BAD-KEY'])->assertSessionHasErrors('api_key');
        $this->assertSame($before, DB::table('sending_servers')->where('id', $channel->sending_server_id)->value('api_key'));
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['status' => 'active']]])]);
        $this->put($url, ['api_key' => 'NEW-GOOD-KEY'])->assertSessionHasNoErrors();

        $after = DB::table('sending_servers')->where('id', $channel->sending_server_id)->value('api_key');
        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('enc:v1:', $after);
        $this->assertSame('NEW-GOOD-KEY', $channel->fresh()->sendingServer->api_key);
        $this->assertNull($channel->fresh()->last_error_code);
    }

    public function test_the_seal_command_upgrades_only_agency_credentials_and_is_idempotent(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $plainAgency = $this->byoChannel($agency['workspace'], ['sealed' => false, 'key' => 'agency-plain-key']);
        $growth = SendingServer::create(['name' => 'Growth', 'settings' => SendingServer::TYPE_TELNYX, 'status' => true, 'plain' => true, 'api_key' => 'growth-plain-key', 'user_id' => 1]);

        $this->artisan('agency-channels:seal-credentials', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('agency-plain-key', DB::table('sending_servers')->where('id', $plainAgency->sending_server_id)->value('api_key'));

        $this->artisan('agency-channels:seal-credentials')->assertExitCode(0);
        $this->artisan('agency-channels:seal-credentials')->assertExitCode(0);

        $raw = DB::table('sending_servers')->where('id', $plainAgency->sending_server_id)->value('api_key');
        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertSame('agency-plain-key', $plainAgency->fresh()->sendingServer->api_key);
        $this->assertSame('growth-plain-key', DB::table('sending_servers')->where('id', $growth->id)->value('api_key'), 'Growth/admin servers are never touched.');
    }

    // ------------------------------------------------------------------ choosing the sender

    public function test_choosing_a_byo_sender_needs_a_working_connection_and_a_webhook_key_and_is_exclusive(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $ws = $agency['workspace'];
        $url = route('customer.workspaces.prospecting.phone-messaging.sender', $ws->uid);
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['status' => 'active']]])]);

        $noKey = $this->byoChannel($ws, ['webhook_key' => false]);
        $this->post($url, ['sender' => $noKey->uid])->assertSessionHas('flash_error');
        $this->assertFalse($noKey->fresh()->is_default_sender);

        $a = $this->byoChannel($ws);
        $b = $this->byoChannel($ws);
        $this->post($url, ['sender' => $a->uid])->assertSessionHas('flash_success');
        $this->post($url, ['sender' => $b->uid])->assertSessionHas('flash_success');

        $this->assertSame([false, true], [$a->fresh()->is_default_sender, $b->fresh()->is_default_sender], 'Exactly one default.');
        $this->assertSame(1, AgencyProspectingChannel::where('workspace_id', $ws->id)->where('is_default_sender', true)->count());

        // A key revoked since connecting is caught at the moment of choosing.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 403)]);
        $this->post($url, ['sender' => $a->uid])->assertSessionHas('flash_error');
        $this->assertTrue($b->fresh()->is_default_sender, 'The previous sender stays in place.');

        // Back to the number bought through MotionGrove.
        $this->post($url, ['sender' => 'managed'])->assertSessionHas('flash_success');
        $this->assertSame(0, AgencyProspectingChannel::where('workspace_id', $ws->id)->where('is_default_sender', true)->count());
    }

    public function test_another_agencys_channel_cannot_be_chosen_and_managed_requires_a_ready_number(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $other = $this->outreachAgency('Beta');
        $this->authenticateAs($agency['customer']);
        $foreign = $this->byoChannel($other['workspace']);
        $url = route('customer.workspaces.prospecting.phone-messaging.sender', $agency['workspace']->uid);

        $this->post($url, ['sender' => $foreign->uid])->assertSessionHas('flash_error');
        $this->assertFalse($foreign->fresh()->is_default_sender);

        $noNumber = $this->outreachAgency('Gamma', ['number' => false]);
        $this->authenticateAs($noNumber['customer']);
        $this->post(route('customer.workspaces.prospecting.phone-messaging.sender', $noNumber['workspace']->uid), ['sender' => 'managed'])
            ->assertSessionHas('flash_error');
    }

    public function test_a_foreign_agency_cannot_open_or_change_my_channel_or_hub(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $other = $this->outreachAgency('Beta');
        $mine = $this->byoChannel($agency['workspace']);
        $this->authenticateAs($other['customer']);

        $this->get(route('customer.workspaces.prospecting.channels.show', [$other['workspace']->uid, $mine->uid]))->assertStatus(404);
        $this->post(route('customer.workspaces.prospecting.channels.disconnect', [$other['workspace']->uid, $mine->uid]))->assertStatus(404);
        $this->post(route('customer.workspaces.prospecting.channels.enable', [$other['workspace']->uid, $mine->uid]))->assertStatus(404);
        $this->get(route('customer.workspaces.prospecting.phone-messaging', $agency['workspace']->uid))->assertStatus(404);
        $this->assertSame('active', $mine->fresh()->status);
    }

    // ------------------------------------------------------------------ disable / disconnect / reconnect

    public function test_disconnect_erases_credentials_blocks_the_webhook_and_cannot_hit_the_current_sender(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $ws = $agency['workspace'];
        $current = $this->byoChannel($ws, ['default' => true]);
        $spare = $this->byoChannel($ws);

        $this->post(route('customer.workspaces.prospecting.channels.disconnect', [$ws->uid, $current->uid]))->assertSessionHas('flash_error');
        $this->assertSame('active', $current->fresh()->status);

        $this->post(route('customer.workspaces.prospecting.channels.disconnect', [$ws->uid, $spare->uid]))->assertSessionHas('flash_success');

        $spare = $spare->fresh();
        $this->assertSame('disconnected', $spare->status);
        $this->assertNull(DB::table('sending_servers')->where('id', $spare->sending_server_id)->value('api_key'));
        $this->assertNull($spare->getRawOriginal('webhook_public_key'));
        $this->assertNotNull($spare->disconnected_at);
        $this->signedWebhook($spare, $this->receivedPayload($spare, 'hi'))->assertStatus(404);

        $this->post(route('customer.workspaces.prospecting.channels.enable', [$ws->uid, $spare->uid]))->assertSessionHas('flash_error');
        $this->assertSame('disconnected', $spare->fresh()->status);

        // Reconnect with fresh credentials: back on, but never the sender automatically.
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => []]), 'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['status' => 'active']]])]);
        $this->put(route('customer.workspaces.prospecting.channels.update', [$ws->uid, $spare->uid]), ['api_key' => 'FRESH', 'c1' => 'new-profile'])->assertSessionHasNoErrors();
        $spare = $spare->fresh();
        $this->assertSame(['active', false], [$spare->status, $spare->is_default_sender]);
        $this->assertSame('FRESH', $spare->sendingServer->api_key);
    }

    // ------------------------------------------------------------------ readiness & the page

    public function test_readiness_follows_the_chosen_byo_connection_and_does_not_need_the_wallet(): void
    {
        $agency = $this->outreachAgency('Alpha', ['number' => false, 'registration' => null, 'balance' => 0]);
        $this->authenticateAs($agency['customer']);
        $channel = $this->byoChannel($agency['workspace'], ['default' => true, 'sender' => '12025552000']);

        $readiness = app(AgencyOutreachReadiness::class)->forWorkspace($agency['workspace']);

        foreach (['number', 'verification', 'wallet'] as $key) {
            $this->assertTrue($readiness->item($key)['ok'], $key);
        }
        $this->assertSame('12025552000', $readiness->info()['number']);

        $channel->forceFill(['status' => AgencyProspectingChannel::STATUS_DISABLED])->save();
        $blocked = app(AgencyOutreachReadiness::class)->forWorkspace($agency['workspace'])->item('number');
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('turned off', $blocked['reason']);
        $this->assertSame(route('customer.workspaces.prospecting.phone-messaging', $agency['workspace']->uid), $blocked['url']);
    }

    public function test_the_page_shows_both_options_separately_labelled_with_the_current_sender(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $channel = $this->byoChannel($agency['workspace'], ['default' => true, 'sender' => '12025552000']);
        $managedNumber = DB::table('business_messaging_numbers')->value('phone_number');

        $page = $this->get(route('customer.workspaces.prospecting.phone-messaging', $agency['workspace']->uid))->assertOk();

        $page->assertSee('Option A')->assertSee('Option B')
            ->assertSee('12025552000')->assertSee($managedNumber)
            ->assertSee('billed by Telnyx')->assertSee('billed to your wallet')
            ->assertSee('Current sender');
        $this->assertSame(1, substr_count($page->getContent(), 'Current sender'), 'Only one sender is current.');

        // Reachable from Outreach itself.
        $this->get($this->outreachRoute('overview', $agency['workspace']))->assertOk()->assertSee('Phone &amp; Messaging', false);
    }
}
