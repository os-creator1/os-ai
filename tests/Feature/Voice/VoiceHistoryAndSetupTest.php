<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Timeline\ContactActivityTimeline;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesNonOwnerStaff;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * Where calls show up (Contact page, Conversation timeline) and how a number becomes callable (explicit,
 * per-number, read-only Telnyx check). Also: view-as and logout/workspace-switch behaviour.
 */
class VoiceHistoryAndSetupTest extends TestCase
{
    use RefreshDatabase;
    use BuildsVoiceFixtures;
    use CreatesNonOwnerStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoice();
    }

    private function callRow(\App\Models\Business $business, array $attrs = []): VoiceCall
    {
        return VoiceCall::create(array_merge([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'workspace_id' => $business->workspace_id,
            'direction' => 'outbound', 'from_number' => '+14155550199', 'to_number' => '+12025550111', 'counterpart_number' => '+12025550111',
            'status' => 'completed', 'status_rank' => 4, 'initiated_at' => now()->subMinutes(5), 'answered_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(2), 'duration_seconds' => 185, 'note' => 'Booked Saturday',
        ], $attrs));
    }

    /* --------------------------------------------------------------- 12. history visibility */

    public function test_a_contacts_page_lists_their_calls_with_outcome_duration_and_note(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $contact = $this->contactFor($business);
        $this->callRow($business, ['contact_id' => $contact->id]);
        $this->callRow($business, ['direction' => 'inbound', 'from_number' => '+12025550111', 'to_number' => '+14155550199', 'status' => 'missed', 'status_rank' => 4, 'duration_seconds' => 0, 'note' => null, 'answered_at' => null, 'initiated_at' => now()->subHour()]);

        $html = $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="contact-calls"', $html);
        $this->assertStringContainsString('Outgoing call · Completed', $html);
        $this->assertStringContainsString('3m 05s', $html);
        $this->assertStringContainsString('Note: Booked Saturday', $html);
        $this->assertStringContainsString('Incoming call · Missed', $html);
        $this->assertStringNotContainsString('No activity yet', $html);
    }

    public function test_the_conversation_timeline_shows_calls_as_activity_not_as_text_messages(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $contact = $this->contactFor($business);
        $box = $this->conversationWith($business, '12025550111');
        $this->message($box, 'incoming', 'Call me', now()->subMinutes(30));
        $this->callRow($business, ['contact_id' => $contact->id]);

        $json = $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $box->uid]))->assertOk()->json('timeline');

        $this->assertStringContainsString('Outgoing call · Completed', $json);
        $this->assertStringContainsString('data-role="timeline-activity"', $json);
        $page = app(ContactActivityTimeline::class)->forConversation($business, $box, $contact);
        $kinds = collect($page->items)->mapWithKeys(fn ($i) => [$i->key => $i->isMessage()]);
        $this->assertTrue($kinds->filter(fn ($isMessage, $key) => str_starts_with($key, 'voice_call:') && $isMessage)->isEmpty(), 'A call is never modelled as a message.');
    }

    public function test_calls_match_by_number_when_the_contact_is_not_linked_and_never_cross_businesses(): void
    {
        [$business] = $this->signedInBusiness(name: 'Mine');
        [, $other] = $this->tenant(WorkspacePlanTier::Core, 'Theirs', 'Theirs WS');
        $box = $this->conversationWith($business, '12025550111');
        $this->callRow($business); // same number, no contact link
        $this->callRow($other->fresh()); // another Business, same number

        $page = app(ContactActivityTimeline::class)->forConversation($business, $box, null);

        $this->assertSame(1, collect($page->items)->filter(fn ($i) => str_starts_with($i->key, 'voice_call:'))->count());
    }

    /* ------------------------------------------------------------------ setup and verify */

    public function test_verifying_a_ready_number_records_ready_after_read_only_checks(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, ready: false);
        $this->telnyx->numbers['+14155550199'] = ['owned' => true, 'status' => 'active', 'connection_id' => 'cc-app-1'];
        $this->telnyx->connections['platform-conn-1'] = ['exists' => true, 'active' => true];

        $this->postJson($this->voiceUrl($workspace, $business, 'settings.numbers.verify', [$number->uid]))
            ->assertOk()->assertJson(['ok' => true, 'code' => null]);

        $this->assertSame('ready', $number->fresh()->voice_status);
        $this->assertNotNull($number->fresh()->voice_checked_at);
        $this->assertSame(['inspectNumber', 'inspectCredentialConnection'], array_column($this->telnyx->calls, 0));
    }

    public function test_verification_tells_the_exact_missing_piece(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, ready: false);
        $url = $this->voiceUrl($workspace, $business, 'settings.numbers.verify', [$number->uid]);

        $this->postJson($url)->assertJson(['ok' => false, 'code' => 'number_not_in_account']);
        $this->assertSame('misconfigured', $number->fresh()->voice_status);

        $this->telnyx->numbers['+14155550199'] = ['owned' => true, 'status' => 'active', 'connection_id' => null];
        $this->postJson($url)->assertJson(['ok' => false, 'code' => 'voice_connection_missing']);

        $this->telnyx->connections['platform-conn-1'] = ['exists' => true, 'active' => true];
        $this->postJson($url)->assertJson(['ok' => true])->assertJsonPath('notes.0', fn ($n) => str_contains($n, 'INCOMING calls'));
    }

    public function test_enabling_a_byo_number_needs_its_connection_and_never_returns_the_key(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $channel = $this->byoChannel($agencyWorkspace, ['sender' => '12025550142', 'key' => 'agency-private-key']);
        $url = $this->voiceUrl($agencyWorkspace, $agencyBusiness, 'settings.numbers.save');

        $this->postJson($url, ['source' => 'byo', 'ref' => $channel->id])->assertStatus(422)->assertJson(['code' => 'voice_not_configured']);

        $response = $this->postJson($url, ['source' => 'byo', 'ref' => $channel->id, 'webrtc_connection_id' => 'agency-conn-9'])->assertOk();
        $this->assertSame('unverified', $response->json('voice_status'));
        $this->assertStringNotContainsString('agency-private-key', $response->getContent());

        $row = VoiceNumber::firstOrFail();
        $this->telnyx->numbers['+12025550142'] = ['owned' => true, 'status' => 'active', 'connection_id' => 'x'];
        $this->telnyx->connections['agency-conn-9'] = ['exists' => true, 'active' => true];
        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'settings.numbers.verify', [$row->uid]))->assertJson(['ok' => true]);

        $this->assertSame('agency-private-key', $this->telnyx->callsTo('inspectNumber')[0]['apiKey'], 'The agency\'s check runs on the agency\'s own account.');
        $this->assertSame('agency-conn-9', $this->telnyx->callsTo('inspectCredentialConnection')[0]['connectionId']);
    }

    public function test_a_number_cannot_be_enabled_from_another_business_or_for_an_ineligible_user(): void
    {
        [$business, $workspace, $owner] = $this->signedInBusiness(name: 'Mine');
        $mine = $this->managedVoiceNumber($business);
        $mine->delete();
        [, $other] = $this->tenant(WorkspacePlanTier::Core, 'Theirs', 'Theirs WS');
        $theirs = $this->managedVoiceNumber($other->fresh(), '+14155550333');
        $url = $this->voiceUrl($workspace, $business, 'settings.numbers.save');

        // Their messaging number id, from my Business.
        $this->postJson($url, ['source' => 'managed', 'ref' => $theirs->messaging_number_id])->assertStatus(404)->assertJson(['code' => 'number_not_allowed']);

        // A user outside this Workspace as the inbound ringer.
        $stranger = $this->createCustomer()->user;
        $this->postJson($url, ['source' => 'managed', 'ref' => $mine->messaging_number_id, 'inbound_user_id' => $stranger->id])
            ->assertStatus(422)->assertJson(['code' => 'inbound_user_not_allowed']);
        $this->assertSame(1, VoiceNumber::count(), 'Only the other tenant\'s own row exists.');
    }

    public function test_staff_without_number_management_cannot_open_or_change_voice_settings(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, ready: false);
        $defaults = json_decode((string) \App\Models\Customer::customerPermissions(), true);
        $staff = $this->nonOwnerStaff($workspace, array_values(array_diff(array_merge($defaults, ['chat_box']), ['view_numbers', 'buy_numbers', 'release_numbers'])));
        $staff->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($staff->user);

        $this->postJson($this->voiceUrl($workspace, $business, 'settings.numbers.verify', [$number->uid]))->assertStatus(404);
        $this->postJson($this->voiceUrl($workspace, $business, 'settings.numbers.save'), ['source' => 'managed', 'ref' => $number->messaging_number_id])->assertStatus(404);
        $this->get($this->voiceUrl($workspace, $business, 'settings'))->assertNotFound();
    }

    public function test_the_settings_page_shows_numbers_and_the_webhook_urls_to_configure(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $this->managedVoiceNumber($agencyBusiness, '+14155550188');

        $html = $this->get($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="voice-number"', $html);
        $this->assertStringContainsString(route('voice.webhooks.telnyx_managed'), $html);
        $this->assertStringContainsString('/webhooks/agency-telnyx-voice/', $html);
        $this->assertStringNotContainsString('agency-private-key', $html);
        $this->assertStringNotContainsString('platform-key-never-leaks', $html);
    }

    /* --------------------------------------------------- 13. view-as, workspace and logout */

    public function test_calling_is_refused_while_an_agency_is_viewing_as_a_client(): void
    {
        $fx = $this->createAgencyManagedClient();
        $this->assignTier($fx['clientWorkspace'], WorkspacePlanTier::Core);
        $client = $fx['clientBusiness']->fresh();
        $number = $this->managedVoiceNumber($client);
        $contact = $this->contactFor($client);
        $this->signIn($fx['agencyOwner']);

        $this->post(route('customer.workspaces.clients.view-as', [$fx['agencyWorkspace']->uid, $fx['clientWorkspace']->uid]))->assertRedirect();

        $this->getJson($this->voiceUrl($fx['clientWorkspace'], $client, 'options') . '?contact_uid=' . $contact->uid)->assertStatus(404);
        $this->postJson($this->voiceUrl($fx['clientWorkspace'], $client, 'token'), ['number_uid' => $number->uid])->assertStatus(404);
        $this->postJson($this->voiceUrl($fx['clientWorkspace'], $client, 'calls.start'), $this->startPayload($number, $contact))->assertStatus(404);
        $this->assertSame(0, VoiceCall::count());
        $html = $this->get(route('customer.workspaces.businesses.people.show', [$fx['clientWorkspace']->uid, $client->uid, $contact->uid]))->getContent();
        $this->assertStringNotContainsString('MGSoftphone.boot', $html);
    }

    public function test_going_offline_on_logout_or_switch_stops_inbound_ringing(): void
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, '+14155550199', true, $user->id);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();
        $this->postJson($this->voiceUrl($workspace, $business, 'heartbeat'), ['number_uid' => $number->uid])->assertOk();
        $this->postJson($this->voiceUrl($workspace, $business, 'offline'))->assertOk();

        $this->managedVoiceWebhook('i1', 'call.initiated', ['call_session_id' => 's1', 'call_control_id' => 'c1', 'direction' => 'incoming', 'from' => '+12025550111', 'to' => '+14155550199'])->assertOk();

        $this->assertSame('assigned_user_offline', VoiceCall::firstOrFail()->hangup_cause);
    }

    public function test_a_user_who_loses_access_stops_receiving_the_calls_assigned_to_them(): void
    {
        [$business, $workspace, $owner] = $this->signedInBusiness();
        $staff = $this->nonOwnerStaff($workspace, ['chat_box']);
        $number = $this->managedVoiceNumber($business, '+14155550199', true, $staff->user_id);
        \App\Models\WorkspaceMembership::query()->where('user_id', $staff->user_id)->update(['is_active' => false]);
        \App\Models\VoiceRegistration::create([
            'uid' => (string) Str::uuid(), 'user_id' => $staff->user_id, 'business_id' => $business->id, 'scope_key' => 'managed',
            'connection_id' => 'platform-conn-1', 'telnyx_credential_id' => 'c', 'sip_username' => 'gencredX', 'last_seen_at' => now(),
        ]);

        $this->managedVoiceWebhook('i1', 'call.initiated', ['call_session_id' => 's1', 'call_control_id' => 'c1', 'direction' => 'incoming', 'from' => '+12025550111', 'to' => '+14155550199'])->assertOk();

        $this->assertSame('no_assigned_user', VoiceCall::firstOrFail()->hangup_cause);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
    }
}
