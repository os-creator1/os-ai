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
        $this->post($this->route('verify', $second));
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
}
