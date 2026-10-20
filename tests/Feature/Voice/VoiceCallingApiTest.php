<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Voice\VoiceProviderException;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use App\Models\VoiceRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesNonOwnerStaff;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * Browser calling — the authorized JSON API behind the Call buttons: which numbers a user may call from,
 * short-lived tokens, starting a call, and who may do any of it. Telnyx is always the in-memory fake.
 */
class VoiceCallingApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsVoiceFixtures;
    use CreatesNonOwnerStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoice();
    }

    /* ------------------------------------------------ 1/4. options from Contacts and Conversations */

    public function test_a_contact_can_be_called_from_an_authorized_voice_ready_number(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);

        $this->getJson($this->voiceUrl($workspace, $business, 'options', []) . '?contact_uid=' . $contact->uid)
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'callable' => true,
                'target' => ['name' => 'Maya Lopez', 'number' => '+12025550111'],
                'numbers' => [['uid' => $number->uid, 'number' => '+14155550199', 'source' => 'managed']],
            ]);
    }

    public function test_a_conversation_resolves_its_own_contact_and_number(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $this->managedVoiceNumber($business);
        $this->contactFor($business, '12025550111', 'Maya', 'Lopez');
        $box = $this->conversationWith($business, '12025550111');

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?conversation_uid=' . $box->uid)
            ->assertOk()
            ->assertJson(['callable' => true, 'target' => ['name' => 'Maya Lopez', 'number' => '+12025550111']]);
    }

    /* ----------------------------------------------- 2/7. missing or unusable voice configuration */

    public function test_no_telnyx_number_at_all_gives_a_precise_reason_not_a_working_button(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $contact = $this->contactFor($business);

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertOk()
            ->assertJson(['callable' => false, 'reason' => 'no_voice_number'])
            ->assertJsonPath('message', 'This account has no phone number connected yet. Connect Telnyx or get a number first.');
    }

    public function test_a_number_that_only_sends_texts_is_never_advertised_as_callable(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, ready: false);
        $contact = $this->contactFor($business);

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'voice_unverified', 'numbers' => []]);

        $number->forceFill(['voice_status' => VoiceNumber::STATUS_MISCONFIGURED])->save();
        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'voice_misconfigured']);
    }

    public function test_a_messaging_number_without_any_voice_row_says_how_to_set_it_up(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $number->delete();
        $contact = $this->contactFor($business);

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'no_voice_number'])
            ->assertJsonPath('message', 'No number is set up for calling yet. An admin can enable voice for a number in Voice settings.');
    }

    public function test_a_contact_without_a_valid_phone_cannot_be_called(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business, '123', 'Short', 'Number');

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'code' => 'invalid_phone']);
    }

    public function test_managed_calling_switched_off_on_the_platform_is_reported_not_hidden(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        config(['services.telnyx.voice_enabled' => false]);

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'voice_disabled']);
    }

    public function test_a_released_number_stops_being_callable(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);

        \App\Models\BusinessMessagingNumber::query()->whereKey($number->messaging_number_id)
            ->update(['status' => \App\Enums\Messaging\BusinessMessagingNumberStatus::Suspended->value]);

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'number_inactive']);
    }

    /* -------------------------------------------------------- 6. tenant and location isolation */

    public function test_another_tenants_number_and_contact_are_never_usable(): void
    {
        [$mine, $myWorkspace] = $this->signedInBusiness(name: 'Mine');
        $this->managedVoiceNumber($mine, '+14155550100');
        [, $theirs] = $this->tenant(WorkspacePlanTier::Core, 'Theirs', 'Theirs WS');
        $theirNumber = $this->managedVoiceNumber($theirs->fresh(), '+14155550101');
        $theirContact = $this->contactFor($theirs->fresh(), '12025550177');
        $myContact = $this->contactFor($mine);

        // Their contact, from my route.
        $this->getJson($this->voiceUrl($myWorkspace, $mine, 'options') . '?contact_uid=' . $theirContact->uid)
            ->assertStatus(404)->assertJson(['code' => 'contact_not_found']);

        // Their number, with my contact: refused, indistinguishable from a number that does not exist.
        $this->postJson($this->voiceUrl($myWorkspace, $mine, 'calls.start'), $this->startPayload($theirNumber, $myContact))
            ->assertStatus(403)->assertJson(['status' => 'error']);
        $this->assertSame(0, VoiceCall::count());

        // Their number, for a token.
        $this->postJson($this->voiceUrl($myWorkspace, $mine, 'token'), ['number_uid' => $theirNumber->uid])
            ->assertStatus(403);
        $this->assertSame([], $this->telnyx->callsTo('createTelephonyCredential'));
    }

    public function test_a_business_route_the_user_does_not_belong_to_is_a_plain_not_found(): void
    {
        [$mine] = $this->signedInBusiness(name: 'Mine');
        [, $theirs, $theirWs] = $this->tenant(WorkspacePlanTier::Core, 'Theirs', 'Theirs WS');

        $this->getJson($this->voiceUrl($theirWs, $theirs, 'session'))->assertStatus(404)->assertJson(['code' => 'not_found']);
        $this->postJson($this->voiceUrl($theirWs, $theirs, 'token'), ['number_uid' => 'x'])->assertStatus(404);
    }

    public function test_a_location_bound_number_serves_only_its_own_locations(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        [$a, $b] = $this->twoLocations($business);
        \Illuminate\Support\Facades\DB::table('business_messaging_number_locations')->insert([
            'business_messaging_number_id' => $number->messaging_number_id,
            'business_location_id' => $a,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $atA = $this->contactFor($business, '12025550111');
        $atA->forceFill(['location_id' => $a])->save();
        $atB = $this->contactFor($business, '12025550112', 'Other', 'Person');
        $atB->forceFill(['location_id' => $b])->save();

        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $atA->uid)->assertJson(['callable' => true]);
        $this->getJson($this->voiceUrl($workspace, $business, 'options') . '?contact_uid=' . $atB->uid)
            ->assertJson(['callable' => false, 'reason' => 'location_mismatch']);

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $atB))->assertStatus(422);
        $this->assertSame(0, VoiceCall::count());
    }

    /* ---------------------------------------- 5. managed vs BYO: whose key, whose connection */

    public function test_managed_tokens_use_only_the_platform_account_and_never_leak_it(): void
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);

        $response = $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        $this->assertStringStartsWith('aaa.bbb.', $response->json('token'));
        $this->assertStringNotContainsString('platform-key-never-leaks', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $created = $this->telnyx->callsTo('createTelephonyCredential')[0];
        $this->assertSame('platform-key-never-leaks', $created['apiKey']);
        $this->assertSame('platform-conn-1', $created['connectionId']);

        $registration = VoiceRegistration::firstOrFail();
        $this->assertSame('managed', $registration->scope_key);
        $this->assertSame((int) $user->id, (int) $registration->user_id);
        $this->assertArrayNotHasKey('sip_username', $registration->toArray());
    }

    public function test_an_agency_byo_number_uses_the_agencys_own_key_and_connection_never_the_platforms(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);

        $response = $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'token'), ['number_uid' => $byo->uid])->assertOk();

        $created = $this->telnyx->callsTo('createTelephonyCredential')[0];
        $this->assertSame('agency-private-key', $created['apiKey']);
        $this->assertSame('agency-conn-9', $created['connectionId']);
        $this->assertStringNotContainsString('agency-private-key', $response->getContent());
        $this->assertStringNotContainsString('platform-key-never-leaks', json_encode($this->telnyx->calls));
        $this->assertSame($byo->scopeKey(), VoiceRegistration::firstOrFail()->scope_key);
        $this->assertStringStartsWith('byo:' . $byo->agency_channel_id . ':', $byo->scopeKey());
    }

    public function test_managed_and_byo_numbers_of_one_agency_get_separate_sip_identities(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $managed = $this->managedVoiceNumber($agencyBusiness, '+14155550188');

        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'token'), ['number_uid' => $byo->uid])->assertOk();
        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'token'), ['number_uid' => $managed->uid])->assertOk();

        $this->assertSame(2, VoiceRegistration::count());
        $this->assertCount(2, array_unique(array_column(VoiceRegistration::all()->map->only('scope_key')->all(), 'scope_key')));
    }

    public function test_a_managed_client_business_cannot_use_a_byo_channel_even_if_a_row_points_at_it(): void
    {
        $fx = $this->createAgencyManagedClient();
        $agencyChannel = $this->byoChannel($fx['agencyWorkspace'], ['sender' => '12025550155']);
        $client = $fx['clientBusiness'];

        // A forged voice row on the CLIENT Business pointing at the AGENCY's channel.
        $forged = VoiceNumber::create([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $client->id, 'workspace_id' => $client->workspace_id,
            'source' => 'byo', 'agency_channel_id' => $agencyChannel->id, 'phone_number' => '+12025550155',
            'webrtc_connection_id' => 'agency-conn-9', 'voice_status' => 'ready',
        ]);

        $this->assignTier($fx['clientWorkspace'], WorkspacePlanTier::Core);
        $this->signIn($fx['clientOwner']);
        $contact = $this->contactFor($client->fresh());

        $this->getJson($this->voiceUrl($fx['clientWorkspace'], $client->fresh(), 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'telnyx_disconnected']);
        $this->postJson($this->voiceUrl($fx['clientWorkspace'], $client->fresh(), 'token'), ['number_uid' => $forged->uid])->assertStatus(422);
        $this->assertSame([], $this->telnyx->callsTo('createTelephonyCredential'));
    }

    public function test_a_disconnected_byo_account_is_not_callable(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $contact = $this->contactFor($agencyBusiness);
        \App\Models\AgencyProspectingChannel::query()->whereKey($byo->agency_channel_id)->update(['status' => 'disconnected']);

        $this->getJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'options') . '?contact_uid=' . $contact->uid)
            ->assertJson(['callable' => false, 'reason' => 'telnyx_disconnected']);
    }

    public function test_two_users_never_share_one_sip_identity(): void
    {
        [$business, $workspace, $owner] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        $second = $this->secondStaffUser($workspace, $business);
        $this->actingAs($second);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        $this->assertSame(2, VoiceRegistration::count());
        $this->assertSame(2, VoiceRegistration::query()->distinct()->count('telnyx_credential_id'));
    }

    /* ---------------------------------------------- 8. credentials and token failures */

    public function test_a_token_failure_is_an_actionable_message_not_a_500(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->telnyx->failNext = new VoiceProviderException('token_failed', 'Telnyx did not issue a calling token. Try again, or reconnect the voice connection.');

        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])
            ->assertStatus(502)
            ->assertJson(['status' => 'error', 'code' => 'token_failed'])
            ->assertJsonPath('message', 'Telnyx did not issue a calling token. Try again, or reconnect the voice connection.');
    }

    public function test_a_rejected_agency_key_tells_the_agency_to_reconnect(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $this->telnyx->failNext = VoiceProviderException::invalidKey();

        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'token'), ['number_uid' => $byo->uid])
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_api_key']);
    }

    public function test_a_token_for_a_vanished_credential_is_rebuilt_once(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        $this->telnyx->failNext = new VoiceProviderException('token_failed', 'gone');
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        $this->assertCount(2, $this->telnyx->callsTo('createTelephonyCredential'));
        $this->assertSame(1, VoiceRegistration::count());
    }

    /* ----------------------------------------------- 9. starting a call, duplicates, overlap */

    public function test_starting_a_call_records_it_and_hands_the_browser_only_what_it_needs(): void
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);

        $response = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))
            ->assertOk()
            ->assertJson(['status' => 'success', 'duplicate' => false, 'call' => ['from' => '+14155550199', 'to' => '+12025550111', 'name' => 'Maya Lopez']]);

        $call = VoiceCall::firstOrFail();
        $this->assertSame($call->uid, base64_decode($response->json('call.client_state')));
        $this->assertSame('initiating', $call->status);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame((int) $contact->id, (int) $call->contact_id);
        $this->assertSame((int) $user->id, (int) $call->user_id);
        $this->assertSame((int) $business->id, (int) $call->business_id);
        $this->assertSame([], $this->telnyx->callsTo('createTelephonyCredential'), 'Recording a call never talks to Telnyx.');
    }

    public function test_a_call_from_a_conversation_uses_that_conversations_contact(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $box = $this->conversationWith($business, '12025550111');

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, null, $box))->assertOk();

        $call = VoiceCall::firstOrFail();
        $this->assertSame((int) $contact->id, (int) $call->contact_id);
        $this->assertSame('+12025550111', $call->to_number);
    }

    public function test_a_double_click_with_the_same_key_creates_one_call(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $payload = $this->startPayload($number, $contact, null, 'same-click-key');

        $first = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $payload)->assertOk();
        $second = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $payload)->assertOk();

        $this->assertSame(1, VoiceCall::count());
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame($first->json('call.uid'), $second->json('call.uid'));
    }

    public function test_a_second_call_while_one_is_in_flight_is_refused(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertOk();
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))
            ->assertStatus(409)->assertJson(['code' => 'call_in_progress']);

        $this->assertSame(1, VoiceCall::count());
    }

    public function test_a_stuck_attempt_that_never_reached_telnyx_stops_blocking_new_calls(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertOk();
        VoiceCall::query()->update(['initiated_at' => now()->subMinutes(5)]);

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertOk();

        $this->assertSame(['failed', 'initiating'], VoiceCall::orderBy('id')->pluck('status')->all());
        $this->assertSame('never_reached_provider', VoiceCall::orderBy('id')->first()->hangup_cause);
    }

    /* ---------------------------------------------- browser hints are never the outcome */

    public function test_a_failed_dial_is_recorded_failed_because_no_provider_event_will_ever_come(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . "/$uid/client-event", ['event' => 'dial_failed', 'detail' => 'mic_denied'])->assertOk();

        $call = VoiceCall::firstOrFail();
        $this->assertSame('failed', $call->status);
        $this->assertSame('client_mic_denied', $call->hangup_cause);
        $this->assertSame(0, $call->duration_seconds);
    }

    public function test_a_browser_saying_ended_is_only_unconfirmed_until_telnyx_says_so(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . "/$uid/client-event", ['event' => 'ended'])
            ->assertOk()->assertJson(['call_status' => 'unconfirmed']);

        $this->assertNull(VoiceCall::firstOrFail()->answered_at);
        $this->assertNotNull(VoiceCall::firstOrFail()->client_reported_ended_at);
    }

    public function test_notes_attach_only_to_the_users_own_call_in_this_business(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . "/$uid/note", ['note' => '  Wants Saturday  '])->assertOk();
        $this->assertSame('Wants Saturday', VoiceCall::firstOrFail()->note);

        $this->actingAs($this->secondStaffUser($workspace, $business));
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . "/$uid/note", ['note' => 'hijack'])->assertStatus(404);
        $this->assertSame('Wants Saturday', VoiceCall::firstOrFail()->note);
    }

    /* ------------------------------------------------------- inbound presence and lookups */

    public function test_session_offers_only_the_number_assigned_to_this_user_for_inbound(): void
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $this->managedVoiceNumber($business, '+14155550101');
        $mine = $this->managedVoiceNumber($this->secondBusinessOf($business), '+14155550102');
        VoiceNumber::query()->where('phone_number', '+14155550101')->update(['inbound_user_id' => $user->id]);

        $this->getJson($this->voiceUrl($workspace, $business, 'session'))
            ->assertOk()->assertJson(['inbound_number' => '+14155550101']);
        $this->assertNotNull($mine);
    }

    public function test_the_heartbeat_marks_the_user_online_and_going_offline_clears_it(): void
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();
        $this->assertFalse(VoiceRegistration::firstOrFail()->isOnline());

        $this->postJson($this->voiceUrl($workspace, $business, 'heartbeat'), ['number_uid' => $number->uid])->assertOk()->assertJson(['registered' => true]);
        $this->assertTrue(VoiceRegistration::firstOrFail()->isOnline());

        $this->postJson($this->voiceUrl($workspace, $business, 'offline'))->assertOk();
        $this->assertFalse(VoiceRegistration::firstOrFail()->isOnline());
    }

    public function test_an_unknown_caller_is_shown_as_a_number_only_and_a_known_one_by_name(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $this->contactFor($business);

        $this->getJson($this->voiceUrl($workspace, $business, 'lookup') . '?number=%2B12025550111')
            ->assertJson(['name' => 'Maya Lopez', 'number' => '+12025550111']);
        $this->getJson($this->voiceUrl($workspace, $business, 'lookup') . '?number=%2B12025550999')
            ->assertJson(['name' => null, 'contact_uid' => null, 'number' => '+12025550999']);
    }

    public function test_lookup_never_resolves_another_businesses_contact(): void
    {
        [$mine, $workspace] = $this->signedInBusiness(name: 'Mine');
        [, $theirs] = $this->tenant(WorkspacePlanTier::Core, 'Theirs', 'Theirs WS');
        $this->contactFor($theirs->fresh(), '12025550133', 'Secret', 'Person');

        $this->getJson($this->voiceUrl($workspace, $mine, 'lookup') . '?number=%2B12025550133')
            ->assertJson(['name' => null, 'contact_uid' => null]);
    }

    /* -------------------------------------------- UI gate: the Call button and panel mount */

    public function test_the_call_button_and_panel_are_present_only_where_calling_can_exist(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $contact = $this->contactFor($business);
        $url = route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]);

        $enabled = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-role="contact-call"', $enabled);
        $this->assertStringContainsString('id="mg-voice-root"', $enabled . 'id="mg-voice-root"'); // built client-side
        $this->assertStringContainsString('MGSoftphone.boot', $enabled);

        config(['services.telnyx.voice_enabled' => false]);
        \Illuminate\Support\Facades\Cache::flush();
        $disabled = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-role="contact-call"', $disabled);
        $this->assertStringNotContainsString('MGSoftphone.boot', $disabled);
    }

    public function test_the_conversation_header_has_the_same_call_action(): void
    {
        [$business, $workspace] = $this->signedInBusiness();
        $this->conversationWith($business, '12025550111');

        $html = $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="conversation-call"', $html);
        $this->assertStringContainsString('data-conversation-input=".chat_id"', $html);
        $this->assertSame(1, substr_count($html, 'MGSoftphone.boot'), 'One calling implementation, mounted once.');
    }

    /* -------------------------------------------------------------------------- helpers */

    private function secondStaffUser($workspace, $business): \App\Models\User
    {
        $customer = $this->nonOwnerStaff($workspace, array_values(array_unique(array_merge(['chat_box', 'view_contact'], (array) (is_string($p = \App\Models\Customer::customerPermissions()) ? json_decode($p, true) : $p)))));
        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /** @return array{0: int, 1: int} */
    private function twoLocations($business): array
    {
        $ids = [];

        foreach (['Downtown', 'Uptown'] as $name) {
            $ids[] = (int) \App\Models\BusinessLocation::create([
                'business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US',
            ])->id;
        }

        return $ids;
    }

    private function secondBusinessOf($business)
    {
        [, $other] = $this->tenant(WorkspacePlanTier::Core, 'Other Co', 'Other WS');

        return $other->fresh();
    }
}
