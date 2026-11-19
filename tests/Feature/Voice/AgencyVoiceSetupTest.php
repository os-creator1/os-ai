<?php

namespace Tests\Feature\Voice;

use App\Models\AgencyProspectingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/** The agency's Voice setup actions. Telnyx is mocked; the verifier only ever issues GETs and nothing is changed at Telnyx. */
class AgencyVoiceSetupTest extends TestCase
{
    use BuildsAgencyByoChannels;
    use CreatesOutreachUiFixtures;
    use RefreshDatabase;

    private array $agency;
    private AgencyProspectingChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($this->agency['customer']);
        $this->channel = $this->byoChannel($this->agency['workspace'], ['key' => 'agency-voice-key']);
    }

    private function route(string $name, ?AgencyProspectingChannel $channel = null, ?string $workspaceUid = null): string
    {
        return route('customer.workspaces.prospecting.channels.voice.' . $name, [$workspaceUid ?? $this->agency['workspace']->uid, ($channel ?? $this->channel)->uid]);
    }

    private function save(array $o = [])
    {
        return $this->put($this->route('save'), $o + ['voice_connection_id' => 'cc_1', 'voice_webrtc_connection_id' => 'wr_1', 'voice_number' => '+1 (415) 555-0142']);
    }

    private function fakeTelnyxVoice(array $o = []): void
    {
        // A fresh factory each time: with stacked fakes the FIRST matching stub wins, so a re-fake must replace, not add.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'api.telnyx.com/v2/call_control_applications/*' => Http::response(['data' => ['id' => 'cc_1', 'active' => $o['cc_active'] ?? true]], $o['cc_status'] ?? 200),
            'api.telnyx.com/v2/credential_connections/*' => Http::response(['data' => ['id' => 'wr_1', 'active' => true, 'outbound' => ['outbound_voice_profile_id' => $o['ovp'] ?? null]]], $o['wr_status'] ?? 200),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => array_key_exists('number_row', $o) ? $o['number_row'] : [['phone_number' => '+14155550142', 'status' => 'active', 'connection_id' => $o['assigned'] ?? 'cc_1']]], 200),
        ]);
    }

    public function test_saving_stores_the_details_but_verifies_and_enables_nothing(): void
    {
        $this->save()->assertRedirect();
        $channel = $this->channel->fresh();

        $this->assertSame('cc_1', $channel->voice_connection_id);
        $this->assertSame('+14155550142', $channel->voice_number);
        $this->assertFalse($channel->voice_enabled);
        $this->assertNull($channel->voice_connection_verified_at);
        Http::assertNothingSent();
    }

    public function test_verify_marks_each_step_from_telnyxs_own_answers_with_get_requests_only(): void
    {
        $this->save();
        $this->fakeTelnyxVoice();

        $this->post($this->route('verify'))->assertSessionHas('flash_success');
        $channel = $this->channel->fresh();

        $this->assertNotNull($channel->voice_connection_verified_at);
        $this->assertNotNull($channel->voice_webrtc_verified_at);
        $this->assertNotNull($channel->voice_number_verified_at);
        $this->assertSame([], \App\Library\Voice\Byo\AgencyByoVoice::missingSteps($channel));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer agency-voice-key'));
    }

    public function test_verify_names_exactly_what_is_missing(): void
    {
        $this->save();

        $this->fakeTelnyxVoice(['assigned' => 'some_other_connection']);
        $this->post($this->route('verify'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'assigned to a different connection'));
        $this->assertNull($this->channel->fresh()->voice_number_verified_at);
        $this->assertNotNull($this->channel->fresh()->voice_connection_verified_at);

        $this->fakeTelnyxVoice(['cc_status' => 404]);
        $this->post($this->route('verify'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'Voice (Call Control) connection ID was not found'));
        $this->assertNull($this->channel->fresh()->voice_connection_verified_at);

        $this->fakeTelnyxVoice(['ovp' => 'ovp_1']);
        $this->post($this->route('verify'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'Outbound Voice Profile'));
        $this->assertNull($this->channel->fresh()->voice_webrtc_verified_at);

        $this->fakeTelnyxVoice(['number_row' => []]);
        $this->post($this->route('verify'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'not a number in this Telnyx account'));
    }

    public function test_voice_cannot_be_turned_on_until_everything_is_verified_and_the_error_says_what_is_missing(): void
    {
        $this->save();

        $this->post($this->route('enable'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'Voice (Call Control) connection') && str_contains($m, 'Voice number'));
        $this->assertFalse($this->channel->fresh()->voice_enabled);

        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'));
        $this->post($this->route('enable'))->assertSessionHas('flash_success');
        $this->assertTrue($this->channel->fresh()->voice_enabled);

        $this->post($this->route('disable'));
        $this->assertFalse($this->channel->fresh()->voice_enabled);
    }

    public function test_changing_a_detail_takes_voice_offline_until_verified_again(): void
    {
        $this->save();
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'));
        $this->post($this->route('enable'));

        $this->save(['voice_number' => '+14155550143']);
        $channel = $this->channel->fresh();
        $this->assertFalse($channel->voice_enabled);
        $this->assertNull($channel->voice_number_verified_at);
    }

    public function test_only_one_channel_per_agency_has_voice_and_a_number_cannot_be_shared_across_agencies(): void
    {
        $this->save();
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'));
        $this->post($this->route('enable'));

        $second = $this->byoChannel($this->agency['workspace']);
        $this->put($this->route('save', $second), ['voice_connection_id' => 'cc_1', 'voice_webrtc_connection_id' => 'wr_1', 'voice_number' => '+14155550144']);
        $this->fakeTelnyxVoice(['number_row' => [['phone_number' => '+14155550144', 'status' => 'active', 'connection_id' => 'cc_1']]]);
        $this->post($this->route('verify', $second));
        $this->post($this->route('enable', $second));
        $this->assertTrue($second->fresh()->voice_enabled);
        $this->assertFalse($this->channel->fresh()->voice_enabled);

        // Another agency cannot claim the same number.
        $other = $this->outreachAgency('Beta');
        $foreign = $this->byoChannel($other['workspace']);
        $this->authenticateAs($other['customer']);
        $this->put($this->route('save', $foreign, $other['workspace']->uid), ['voice_connection_id' => 'x1', 'voice_webrtc_connection_id' => 'x2', 'voice_number' => '+14155550144'])
            ->assertSessionHasErrors('voice_number');
        $this->assertNull($foreign->fresh()->voice_number_key);
    }


    public function test_unverified_number_cannot_preempt_its_real_owner_and_conflicting_verification_is_rejected(): void
    {
        $other = $this->outreachAgency('Beta');
        $foreign = $this->byoChannel($other['workspace']);

        // Saving an arbitrary known phone number must not reserve it before Telnyx proves ownership.
        $this->authenticateAs($other['customer']);
        $this->put($this->route('save', $foreign, $other['workspace']->uid), [
            'voice_connection_id' => 'cc_1', 'voice_webrtc_connection_id' => 'wr_1', 'voice_number' => '+14155550142',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($foreign->fresh()->voice_number_key);

        $this->authenticateAs($this->agency['customer']);
        $this->save()->assertRedirect()->assertSessionHasNoErrors();
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'))->assertSessionHas('flash_success');
        $this->assertSame('14155550142', $this->channel->fresh()->voice_number_key);

        // Even if a provider response later claims success for an overlapping configuration, only
        // one verified owner can hold the unique key; the loser retains no verified claim.
        $this->authenticateAs($other['customer']);
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify', $foreign, $other['workspace']->uid))
            ->assertSessionHas('flash_error', fn ($message) => str_contains($message, 'already verified'));
        $this->assertNull($foreign->fresh()->voice_number_key);
        $this->assertNull($foreign->fresh()->voice_number_verified_at);
        $this->assertSame('14155550142', $this->channel->fresh()->voice_number_key);
    }

    public function test_changing_a_verified_number_releases_its_previous_ownership_key(): void
    {
        $this->save();
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'))->assertSessionHas('flash_success');
        $this->assertSame('14155550142', $this->channel->fresh()->voice_number_key);

        $this->save(['voice_number' => '+14155550143'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->channel->fresh()->voice_number_key);
        $this->assertNull($this->channel->fresh()->voice_number_verified_at);

        $other = $this->outreachAgency('Beta');
        $foreign = $this->byoChannel($other['workspace']);
        $this->authenticateAs($other['customer']);
        $this->put($this->route('save', $foreign, $other['workspace']->uid), [
            'voice_connection_id' => 'cc_1', 'voice_webrtc_connection_id' => 'wr_1', 'voice_number' => '+14155550142',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_another_agencys_channel_is_not_reachable_and_nothing_is_called(): void
    {
        $other = $this->outreachAgency('Gamma');
        $this->authenticateAs($other['customer']);
        Http::fake();

        foreach (['verify', 'enable', 'disable'] as $action) {
            $this->post($this->route($action, $this->channel, $other['workspace']->uid))->assertNotFound();
        }
        $this->put($this->route('save', $this->channel, $other['workspace']->uid), ['voice_connection_id' => 'a', 'voice_webrtc_connection_id' => 'b', 'voice_number' => '+14155550150'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_channel_page_shows_the_voice_status_and_never_the_key(): void
    {
        $this->get(route('customer.workspaces.prospecting.channels.show', [$this->agency['workspace']->uid, $this->channel->uid]))
            ->assertOk()->assertSee('Voice calling')->assertSee('Missing or not verified')
            ->assertDontSee('agency-voice-key');
    }

    public function test_changing_the_webrtc_connection_purges_only_this_channels_credentials(): void
    {
        $this->save();
        $business = $this->agency['business'];
        $make = fn (int $channelId, int $userId, ?string $conn) => \App\Models\BusinessVoiceBrowserCredential::create([
            'business_id' => $business->id, 'user_id' => $userId, 'agency_prospecting_channel_id' => $channelId,
            'webrtc_connection_id' => $conn, 'telnyx_credential_id' => 'c' . $userId . $channelId, 'sip_username' => 'u' . $userId . $channelId, 'expires_at' => now()->addDays(5),
        ]);
        $other = $this->byoChannel($this->agency['workspace']);
        $make((int) $this->channel->id, 1, 'wr_1');
        $make(0, 2, null);                       // platform-managed credential
        $make((int) $other->id, 3, 'other_wr');  // a different channel's credential

        $this->save(['voice_webrtc_connection_id' => 'wr_2'])->assertRedirect();

        $this->assertSame(0, \App\Models\BusinessVoiceBrowserCredential::where('agency_prospecting_channel_id', $this->channel->id)->count());
        $this->assertSame(1, \App\Models\BusinessVoiceBrowserCredential::where('agency_prospecting_channel_id', 0)->count());
        $this->assertSame(1, \App\Models\BusinessVoiceBrowserCredential::where('agency_prospecting_channel_id', $other->id)->count());
    }

    public function test_a_voice_setting_changed_while_telnyx_is_being_asked_discards_the_result(): void
    {
        $this->save();
        $channelId = (int) $this->channel->id;
        Http::fake([
            'api.telnyx.com/v2/call_control_applications/*' => function () use ($channelId) {
                // The agency edits its WebRTC connection while verification is in flight.
                AgencyProspectingChannel::query()->whereKey($channelId)->update(['voice_webrtc_connection_id' => 'wr_changed']);

                return Http::response(['data' => ['id' => 'cc_1', 'active' => true]], 200);
            },
            'api.telnyx.com/v2/credential_connections/*' => Http::response(['data' => ['id' => 'wr_1', 'active' => true]], 200),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['phone_number' => '+14155550142', 'status' => 'active', 'connection_id' => 'cc_1']]], 200),
        ]);

        $this->post($this->route('verify'))->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'changed while they were being checked'));

        $channel = $this->channel->fresh();
        $this->assertNull($channel->voice_connection_verified_at);
        $this->assertNull($channel->voice_webrtc_verified_at);
        $this->assertNull($channel->voice_number_verified_at);
        $this->assertFalse($channel->voice_enabled);
    }

    public function test_an_api_key_changed_while_telnyx_is_being_asked_discards_the_result(): void
    {
        $this->save();
        $serverId = (int) $this->channel->sending_server_id;
        Http::fake([
            'api.telnyx.com/v2/call_control_applications/*' => function () use ($serverId) {
                \App\Models\SendingServer::query()->whereKey($serverId)->update(['api_key' => \App\Library\Security\SealedSecret::seal('a-different-account-key')]);

                return Http::response(['data' => ['id' => 'cc_1', 'active' => true]], 200);
            },
            'api.telnyx.com/v2/credential_connections/*' => Http::response(['data' => ['id' => 'wr_1', 'active' => true]], 200),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['phone_number' => '+14155550142', 'status' => 'active', 'connection_id' => 'cc_1']]], 200),
        ]);

        $this->post($this->route('verify'))->assertSessionHas('flash_error');
        $this->assertNull($this->channel->fresh()->voice_connection_verified_at);
        $this->assertNull($this->channel->fresh()->voice_number_verified_at);
    }

    public function test_a_disconnected_voice_channel_can_be_released_but_never_falls_back(): void
    {
        $this->channel->forceFill(['voice_enabled' => true, 'status' => AgencyProspectingChannel::STATUS_DISCONNECTED])->save();
        $this->assertTrue($this->channel->fresh()->voice_enabled, 'Disconnecting must not silently hand calling back to platform voice.');

        $this->post($this->route('disable'))->assertRedirect();
        $this->assertFalse($this->channel->fresh()->voice_enabled);
        $this->post($this->route('enable'))->assertNotFound();
    }

    public function test_the_channel_page_offers_voice_off_for_a_disconnected_voice_channel(): void
    {
        $this->channel->forceFill(['voice_enabled' => true, 'status' => AgencyProspectingChannel::STATUS_DISCONNECTED])->save();

        $this->get(route('customer.workspaces.prospecting.channels.show', [$this->agency['workspace']->uid, $this->channel->uid]))
            ->assertOk()->assertSee('Turn voice off')->assertSee('blocked rather than switched');
    }

    public function test_an_unchanged_configuration_is_still_approved(): void
    {
        $this->save();
        $this->fakeTelnyxVoice();
        $this->post($this->route('verify'))->assertSessionHas('flash_success');
        $this->assertNotNull($this->channel->fresh()->voice_number_verified_at);
    }
}
