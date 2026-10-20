<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Models\AgencyChannelSetupReservation;
use App\Models\AgencyProspectingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * The webhook URL is shown BEFORE the Telnyx account is connected and is the same URL afterwards.
 * Telnyx is faked throughout; nothing is sent, bought or billed.
 */
class AgencyByoTelnyxWebhookFirstSetupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;
    use BuildsAgencyByoChannels;

    private function connectUrl(array $agency): string
    {
        return route('customer.workspaces.prospecting.channels.connect', [$agency['workspace']->uid, 'Telnyx']);
    }

    /** @return array{0: string, 1: string} the page's webhook URL, and the public key used to connect */
    private function connect(array $agency, array $overrides = []): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->byoSetupSecret = sodium_crypto_sign_secretkey($keypair);

        return [
            $this->post($this->connectUrl($agency), array_merge(['sender_number' => '+12025552000', 'api_key' => 'KEY-secret-1', 'c1' => 'profile-1', 'webhook_public_key' => $public], $overrides)),
            $public,
        ];
    }

    private ?string $byoSetupSecret = null;

    private function fakeOk(): void
    {
        Http::fake([
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => ['id' => 'p']]),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'n', 'status' => 'active']]]),
        ]);
    }

    private function pageUrl(string $html): string
    {
        $this->assertSame(1, preg_match('~value="(http[^"]*webhooks/agency-telnyx/[^"]+)"~', $html, $m));

        return html_entity_decode($m[1]);
    }

    public function test_the_webhook_url_is_available_before_connecting_with_copy_button_and_steps(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);

        $html = $this->get($this->connectUrl($agency))->assertOk()
            ->assertSee('Copy Webhook URL')->assertSee('Messaging Profile ID')->assertSee('optional')
            ->getContent();

        $url = $this->pageUrl($html);
        $reservation = AgencyChannelSetupReservation::where('workspace_id', $agency['workspace']->id)->sole();
        $this->assertSame(route('prospecting.webhooks.agency_telnyx', [$reservation->uid, AgencyProspectingWebhookToken::forChannel($reservation->uid)]), $url);
        $this->assertStringNotContainsString('/telnyx', substr($url, strlen(url('/webhooks/prospecting'))), 'Not the older AI Prospecting route.');
        $this->assertStringNotContainsString('webhooks/prospecting/', $url);
        $this->assertSame(0, AgencyProspectingChannel::count(), 'Viewing the page creates no channel.');
    }

    public function test_the_url_survives_refresh_return_and_failed_attempts_and_the_connection_uses_it(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);

        $first = $this->pageUrl($this->get($this->connectUrl($agency))->getContent());
        $this->assertSame($first, $this->pageUrl($this->get($this->connectUrl($agency))->getContent()), 'Refresh.');

        // Failed attempts (validation, then a Telnyx rejection) leave the reservation untouched.
        $this->post($this->connectUrl($agency), ['sender_number' => 'bad'])->assertSessionHasErrors('sender_number');
        Http::fake(['api.telnyx.com/v2/messaging_profiles/*' => Http::response([], 401)]);
        $this->connect($agency)[0]->assertSessionHasErrors('api_key');
        $this->assertSame(0, AgencyProspectingChannel::count());
        $this->assertSame($first, $this->pageUrl($this->get($this->connectUrl($agency))->getContent()), 'After failures.');
        $this->assertSame(1, AgencyChannelSetupReservation::count());

        // Return later as a different session of the same agency.
        $this->flushSession();
        $this->authenticateAs($agency['customer']);
        $this->assertSame($first, $this->pageUrl($this->get($this->connectUrl($agency))->getContent()), 'Return later.');

        $this->fakeOk();
        $this->connect($agency)[0]->assertSessionHasNoErrors();

        $channel = AgencyProspectingChannel::where('workspace_id', $agency['workspace']->id)->sole();
        $show = $this->get(route('customer.workspaces.prospecting.channels.show', [$agency['workspace']->uid, $channel->uid]))->assertOk()->getContent();
        $this->assertStringContainsString(htmlspecialchars($first, ENT_QUOTES), $show, 'The Manage page shows the identical URL.');
        $this->assertStringContainsString($channel->uid, $first);
        $this->assertSame(0, AgencyChannelSetupReservation::count(), 'The reservation is consumed.');
        $this->assertFalse($channel->is_default_sender, 'Connecting never silently becomes the sender.');
    }

    public function test_a_pending_url_cannot_send_or_process_events_and_is_not_a_sender(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        Http::fake();

        $url = $this->pageUrl($this->get($this->connectUrl($agency))->getContent());
        $this->postJson($url, ['data' => ['event_type' => 'message.received']])->assertStatus(404);
        $this->postJson($url . 'x', [])->assertStatus(404);

        $this->assertSame(0, AgencyProspectingChannel::count(), 'No channel, so nothing can send or be chosen as a sender.');
        $this->post(route('customer.workspaces.prospecting.phone-messaging.sender', $agency['workspace']->uid), ['sender' => AgencyChannelSetupReservation::first()->uid])->assertSessionHas('flash_error');
        Http::assertNothingSent();
    }

    public function test_another_agency_gets_its_own_url_and_cannot_use_mine(): void
    {
        $alpha = $this->outreachAgency('Alpha');
        $beta = $this->outreachAgency('Beta');
        $this->authenticateAs($alpha['customer']);
        $alphaUrl = $this->pageUrl($this->get($this->connectUrl($alpha))->getContent());

        $this->authenticateAs($beta['customer']);
        $this->get($this->connectUrl($alpha))->assertStatus(404);
        $betaUrl = $this->pageUrl($this->get($this->connectUrl($beta))->getContent());
        $this->assertNotSame($alphaUrl, $betaUrl);

        // Beta connecting never consumes or adopts Alpha's reserved identity.
        $this->fakeOk();
        $this->connect($beta)[0]->assertSessionHasNoErrors();
        $channel = AgencyProspectingChannel::where('workspace_id', $beta['workspace']->id)->sole();
        $this->assertStringContainsString($channel->uid, $betaUrl);
        $this->assertStringNotContainsString($channel->uid, $alphaUrl);
        $this->assertSame($alpha['workspace']->id, AgencyChannelSetupReservation::sole()->workspace_id);
    }

    public function test_a_correctly_signed_inbound_event_works_after_completing_setup_on_the_prereserved_url(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $url = $this->pageUrl($this->get($this->connectUrl($agency))->getContent());

        $this->fakeOk();
        $this->connect($agency)[0]->assertSessionHasNoErrors();
        $channel = AgencyProspectingChannel::where('workspace_id', $agency['workspace']->id)->sole();

        $payload = $this->receivedPayload($channel->load('sendingServer'), 'Yes please', 'in-setup-1');
        $body = json_encode($payload);
        $ts = (string) now()->getTimestamp();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $this->byoSetupSecret));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts];

        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();

        $forged = base64_encode(random_bytes(64));
        $this->call('POST', $url, [], [], [], array_merge($server, ['HTTP_TELNYX_SIGNATURE_ED25519' => $forged]), json_encode($this->receivedPayload($channel, 'Forged', 'in-setup-2')))->assertStatus(403);
    }

    public function test_existing_connected_accounts_keep_their_url_and_work(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $this->authenticateAs($agency['customer']);
        $existing = $this->byoChannel($agency['workspace']);

        $this->signedWebhook($existing, $this->receivedPayload($existing, 'hello', 'in-old-1'))->assertOk();
        $show = $this->get(route('customer.workspaces.prospecting.channels.show', [$agency['workspace']->uid, $existing->uid]))->assertOk()->getContent();
        $this->assertStringContainsString(htmlspecialchars(route('prospecting.webhooks.agency_telnyx', [$existing->uid, AgencyProspectingWebhookToken::forChannel($existing->uid)]), ENT_QUOTES), $show);
    }
}
