<?php

namespace Tests\Feature\Agency;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\AgencyOutreach\OutreachMessageSender;
use App\Library\Conversations\ConversationContextReader;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\User;
use App\Library\Messaging\ManagedTransportBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * The whole agency story end to end, with every provider mocked: turn the agency's own
 * workspace on, find everything in the sidebar, connect its OWN Telnyx account, choose it,
 * send from Outreach, receive a signed reply into its own Conversations/CRM, then switch to
 * the number bought through MotionGrove — with BYO never touching the managed wallet, and
 * other agencies and clients unable to reach any of it.
 */
class AgencyIntegratedMessagingFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;
    use BuildsAgencyByoChannels;

    public function test_the_full_agency_flow_with_byo_then_managed_and_tenant_isolation(): void
    {
        $this->bindFakeAdapter();
        \App\Models\Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        Http::fake([
            'api.telnyx.com/v2/messages' => Http::sequence()->push(['data' => ['id' => 'tx-1']])->push(['data' => ['id' => 'tx-2']]),
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response(['data' => ['id' => 'prof-A']]),
            'api.telnyx.com/v2/phone_numbers/*/messaging' => Http::response(['data' => ['messaging_profile_id' => 'prof-A', 'features' => ['sms' => ['domestic_two_way' => true]]]]),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'num-1', 'status' => 'active']]]),
        ]);

        // 1. An agency whose own Business has not been activated yet, with a MotionGrove number ready.
        $agency = $this->outreachAgency('Northwind', ['enrolled' => false]);
        $workspace = $agency['workspace'];
        $business = $agency['business'];
        $business->forceFill(['status' => BusinessStatus::Draft])->save();
        $this->authenticateAs($agency['customer']);
        $this->switchToAccount($workspace);

        $this->post(route('customer.workspaces.agency.operations.enable', $workspace->uid))->assertRedirect(route('user.home'));

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, AgencyClientWorkspaceRelationship::query()->count(), 'Activating the agency creates no billable client.');

        // 2-3. The sidebar and every operating module.
        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        foreach (['own-business', 'contacts', 'calendar', 'automations', 'seo', 'ads', 'accounts', 'prospecting', 'settings'] as $key) {
            $this->assertContains($key, $keys, $key);
        }
        $this->assertSame(array_values(array_unique($keys)), $keys, 'No duplicated navigation.');

        foreach ([
            'customer.workspaces.businesses.people.index', 'customer.workspaces.businesses.crm.board',
            'customer.workspaces.businesses.calendar.index', 'customer.workspaces.businesses.automations.workflows.index',
            'customer.workspaces.businesses.seo.keywords.index', 'customer.workspaces.businesses.ads.index',
        ] as $route) {
            $module = $this->get(route($route, [$workspace->uid, $business->uid]));
            // Calendar's index forwards to its first location's schedule; every other module opens directly.
            $opens = $module->getStatusCode() === 200
                || ($route === 'customer.workspaces.businesses.calendar.index' && str_contains((string) $module->headers->get('Location'), '/calendar/locations/'));
            $this->assertTrue($opens, $route . ' -> ' . $module->getStatusCode() . ' ' . $module->headers->get('Location'));
        }

        // 4. Phone & Messaging shows both options.
        $hub = $this->get(route('customer.workspaces.prospecting.phone-messaging', $workspace->uid));
        $this->assertSame(200, $hub->getStatusCode(), 'redirected to ' . $hub->headers->get('Location'));
        $hub->assertSee('Option A')->assertSee('Option B');

        // 5. Connect the agency's own Telnyx account.
        $webhookKeypair = sodium_crypto_sign_keypair();
        $this->post(route('customer.workspaces.prospecting.channels.connect', [$workspace->uid, 'Telnyx']), [
            'sender_number' => '+12025552000', 'api_key' => 'AGENCY-OWN-KEY', 'c1' => 'prof-A',
            'webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($webhookKeypair)),
        ])->assertSessionHas('flash_success');
        $channel = AgencyProspectingChannel::where('workspace_id', $workspace->id)->sole();
        $this->byoSecretKeys[(int) $channel->id] = sodium_crypto_sign_secretkey($webhookKeypair);
        $this->assertStringStartsWith('enc:v1:', DB::table('sending_servers')->where('id', $channel->sending_server_id)->value('api_key'));

        // 6. Choose it. Outreach readiness now follows it.
        $this->post(route('customer.workspaces.prospecting.phone-messaging.sender', $workspace->uid), ['sender' => $channel->uid])->assertSessionHas('flash_success');
        $this->assertTrue($channel->fresh()->is_default_sender);

        // 7. An Outreach message goes out through the agency's own Telnyx account, not the wallet.
        $billing = $this->spy(ManagedTransportBilling::class);
        $sent = app(OutreachMessageSender::class)->send($business->fresh(), '12025551000', 'Hi from Northwind', 'e2e-byo-1');
        $this->assertSame('sent', $sent->status, (string) $sent->reason);
        $billing->shouldNotHaveReceived('reserve');
        $request = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn ($r) => str_ends_with($r->url(), '/v2/messages'));
        $this->assertSame('Bearer AGENCY-OWN-KEY', $request->header('Authorization')[0]);
        $this->assertSame('+12025552000', $request['from']);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame('byo', DB::table('business_usage_measurements')->where('idempotency_key', 'e2e-byo-1')->value('transport_marker'));

        // 8-9. A validly signed reply arrives and lands in the agency's own conversation + CRM contact.
        $contact = Contacts::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'group_id' => null,
            'phone' => '12025551000', 'first_name' => 'Dana', 'status' => 'subscribe',
        ]);
        $this->signedWebhook($channel->fresh(), $this->receivedPayload($channel->fresh(), 'Interested, call me'))->assertOk()->assertJson(['status' => 'accepted']);

        $box = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame(['12025552000', '12025551000'], [$box->from, $box->to]);
        $context = app(ConversationContextReader::class)->read($business->fresh(), $box, $contact);
        $this->assertSame((string) $contact->uid, $context->contactUid);
        $this->assertSame((string) $box->to, preg_replace('/\D/', '', (string) $context->phone));

        // 11-13. Switch to the MotionGrove number: managed transport, and only now the wallet is in play.
        $this->post(route('customer.workspaces.prospecting.phone-messaging.sender', $workspace->uid), ['sender' => 'managed'])->assertSessionHas('flash_success');
        $managedSent = app(OutreachMessageSender::class)->send($business->fresh(), '12025553333', 'Via MotionGrove', 'e2e-managed-1');
        $this->assertSame('sent', $managedSent->status, (string) $managedSent->reason);
        $billing->shouldHaveReceived('reserve')->once();
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame('managed', DB::table('business_messaging_operations')->where('operation_key', 'e2e-managed-1')->value('transport_mode'));
        $this->assertSame('byo', DB::table('business_messaging_operations')->where('operation_key', 'e2e-byo-1')->value('transport_mode'), 'History is untouched.');
        $this->assertSame(2, ChatBox::query()->where('business_id', $business->id)->count(), 'Old conversation kept; new one started on the new sender.');

        // 14. Others see nothing: another agency, and a managed client.
        $other = $this->outreachAgency('Southwind');
        $client = $this->tenant(WorkspacePlanTier::Growth, 'Client Co', 'Client Workspace');
        $this->assertNull(AgencyByoSender::selectedChannel($other['business']));
        $this->assertNull(AgencyByoSender::selectedChannel($client[1]));

        foreach ([$other['customer'], $client[0]] as $outsider) {
            $this->authenticateAs($outsider);
            $this->get(route('customer.workspaces.prospecting.phone-messaging', $workspace->uid))->assertStatus(404);
            $this->post(route('customer.workspaces.prospecting.phone-messaging.sender', $workspace->uid), ['sender' => $channel->uid])->assertStatus(404);
            $this->post(route('customer.workspaces.prospecting.channels.disconnect', [$workspace->uid, $channel->uid]))->assertStatus(404);
        }
        $this->assertSame('active', $channel->fresh()->status);
        $this->assertSame(0, ChatBox::query()->where('business_id', $other['business']->id)->count());
    }
}
