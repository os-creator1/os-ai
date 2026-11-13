<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\BusinessVoiceCall;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

class CustomerOutboundCallTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const CONN = 'conn_fixture_1';
    private const PHONE = '14155552671'; // contacts.phone is digits only; E.164 is +14155552671

    private $customer;
    private $business;
    private $workspace;
    private $number;
    private $contact;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.connection_id' => self::CONN,
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(str_repeat('k', 32)),
        ]);

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($this->business);
        $this->number = $this->attachNumber($identity, $this->uniqueNumber(), true, \App\Enums\Messaging\BusinessMessagingNumberStatus::Active, 'num_ref_1');
        $this->voiceReady($this->number->id);
        $this->contact = $this->makeContact($this->business->id, self::PHONE);
        $this->authenticateAs($this->customer);

        Http::fake(['api.telnyx.com/v2/calls' => fn () => Http::response(['data' => ['call_control_id' => 'v3:cc-' . uniqid('', true)]], 200)]);
    }

    private function voiceReady(int $numberId): void
    {
        \DB::table('business_messaging_numbers')->where('id', $numberId)->update([
            'number_type' => 'local', 'voice_connection_id' => self::CONN, 'voice_assigned_at' => now(),
        ]);
    }

    private function makeContact(int $businessId, string $phone): Contacts
    {
        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g' . $phone, 'status' => true]);

        return Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $businessId, 'group_id' => $group->id, 'phone' => $phone, 'status' => 'subscribe']);
    }

    private function place(?string $contactUid = null, string $key = 'req-key-0001', ?array $uids = null)
    {
        [$ws, $biz] = $uids ?? [$this->workspace->uid, $this->business->uid];

        return $this->postJson(route('customer.workspaces.businesses.calls.store', [$ws, $biz]), [
            'contact_uid' => $contactUid ?? $this->contact->uid,
            'idempotency_key' => $key,
        ]);
    }

    private function noDial(): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_an_authorized_user_places_a_call_from_the_voice_assigned_number_with_a_server_side_time_limit(): void
    {
        $response = $this->place()->assertStatus(202)
            ->assertJsonPath('data.status', 'initiated')
            ->assertJsonPath('data.contact_uid', $this->contact->uid)
            ->assertJsonPath('data.audio.browser', false)
            ->assertJsonPath('duplicate', false);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telnyx.com/v2/calls'
            && $r['connection_id'] === self::CONN
            && $r['from'] === $this->number->phone_number
            && $r['to'] === '+14155552671'
            && $r['time_limit_secs'] === 600 && $r['timeout_secs'] === 30);

        $this->assertStringNotContainsString('4155552671', $response->getContent());
        $this->assertStringNotContainsString('fixture_api_key', $response->getContent());

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('customer', $call->kind);
        $this->assertSame($this->contact->id, (int) $call->contact_id);

        $this->getJson(route('customer.workspaces.businesses.calls.show', [$this->workspace->uid, $this->business->uid, $call->uid]))
            ->assertOk()->assertJsonPath('data.id', $call->uid)->assertJsonPath('data.status', 'initiated');
    }

    public function test_a_repeated_request_key_does_not_dial_twice(): void
    {
        $first = $this->place()->assertStatus(202);
        $again = $this->place()->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, BusinessVoiceCall::count());
        Http::assertSentCount(1);
    }

    public function test_the_same_key_for_a_different_contact_is_refused(): void
    {
        $this->place()->assertStatus(202);
        $other = $this->makeContact($this->business->id, '14155552672');

        $this->place($other->uid)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        Http::assertSentCount(1);
    }

    public function test_a_user_without_the_calling_permission_cannot_call(): void
    {
        // The Workspace owner needs no grant (CallingAuthority); this is staff without place_voice_calls.
        \DB::table('workspaces')->where('id', $this->workspace->id)->update(['owner_user_id' => $this->platformAdminId()]);
        $this->authenticateAs($this->customer, ['view_numbers']);

        $this->assertContains($this->place()->getStatusCode(), [401, 403], 'Calling without the permission must be refused.');
        $this->noDial();
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    public function test_another_workspaces_business_and_contact_are_not_reachable(): void
    {
        ['customer' => $otherCustomer, 'business' => $otherBusiness, 'workspace' => $otherWorkspace] = $this->createIndependentWorkspaceBusiness();
        $foreignContact = $this->makeContact($otherBusiness->id, '14155552673');

        // Foreign Business route: 404 before anything else.
        $this->place(null, 'req-key-0002', [$otherWorkspace->uid, $otherBusiness->uid])->assertNotFound();
        // Own Business, but a contact that belongs to another Business: not found, never dialled.
        $this->place($foreignContact->uid, 'req-key-0003')->assertNotFound()->assertJsonPath('error.code', 'contact_not_found');

        $this->noDial();
    }

    public function test_calling_is_refused_when_not_enabled_or_not_live(): void
    {
        config(['messaging.voice.customer_calls.enabled' => false]);
        $this->place()->assertStatus(503)->assertJsonPath('error.code', 'calling_unavailable');

        config(['messaging.voice.customer_calls.enabled' => true, 'services.telnyx.mode' => 'sandbox']);
        $this->place(null, 'req-key-0004')->assertStatus(503);

        $this->noDial();
    }

    public function test_a_number_not_confirmed_on_the_voice_connection_cannot_be_the_caller_id(): void
    {
        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update(['voice_assigned_at' => null]);
        $this->place()->assertStatus(409)->assertJsonPath('error.code', 'number_not_voice_ready');

        $this->voiceReady($this->number->id);
        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update(['voice_connection_id' => 'some_other_connection']);
        $this->place(null, 'req-key-0005')->assertStatus(409);

        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update(['voice_connection_id' => self::CONN, 'number_type' => 'toll_free']);
        $this->place(null, 'req-key-0006')->assertStatus(409);

        $this->noDial();
    }

    public function test_an_invalid_destination_and_bad_input_are_refused(): void
    {
        $bad = $this->makeContact($this->business->id, '123');
        $this->place($bad->uid)->assertStatus(422)->assertJsonPath('error.code', 'invalid_destination');

        $this->postJson(route('customer.workspaces.businesses.calls.store', [$this->workspace->uid, $this->business->uid]), ['contact_uid' => $this->contact->uid, 'idempotency_key' => 'x'])
            ->assertStatus(422);

        $this->noDial();
    }

    public function test_a_contact_already_on_a_call_and_the_concurrency_cap_are_enforced(): void
    {
        config(['messaging.voice.customer_calls.max_concurrent_per_business' => 2]);
        $this->place()->assertStatus(202);

        $this->place($this->contact->uid, 'req-key-0007')->assertStatus(409)->assertJsonPath('error.code', 'contact_call_in_progress');

        $second = $this->makeContact($this->business->id, '14155552672');
        $third = $this->makeContact($this->business->id, '14155552674');
        $this->place($second->uid, 'req-key-0008')->assertStatus(202);
        $this->place($third->uid, 'req-key-0009')->assertStatus(429)->assertJsonPath('error.code', 'too_many_active_calls');

        Http::assertSentCount(2);
    }

    public function test_a_telnyx_failure_is_reported_as_a_failed_call_without_exposing_provider_details(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); // drop the setUp success stub
        Http::fake(['api.telnyx.com/v2/calls' => Http::response(['errors' => [['code' => '10010', 'detail' => 'secret provider text']]], 403)]);

        $response = $this->place()->assertStatus(502)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'http_403');

        $this->assertStringNotContainsString('secret provider text', $response->getContent());
    }

    public function test_a_call_from_one_business_is_not_readable_through_another(): void
    {
        $this->place()->assertStatus(202);
        $call = BusinessVoiceCall::firstOrFail();
        ['customer' => $otherCustomer, 'business' => $otherBusiness, 'workspace' => $otherWorkspace] = $this->createIndependentWorkspaceBusiness();
        $this->authenticateAs($otherCustomer);

        $this->getJson(route('customer.workspaces.businesses.calls.show', [$otherWorkspace->uid, $otherBusiness->uid, $call->uid]))
            ->assertNotFound();
    }

    public function test_do_not_contact_is_scoped_to_this_business_only(): void
    {
        // Another tenant opted this number out: it must not block this Business.
        ['business' => $otherBusiness] = $this->createIndependentWorkspaceBusiness();
        \App\Models\Blacklists::create(['user_id' => $this->customer->user_id, 'business_id' => $otherBusiness->id, 'number' => self::PHONE, 'reason' => 'x']);
        $this->place()->assertStatus(202);

        // This Business's own opt-out does block it.
        $other = $this->makeContact($this->business->id, '14155552672');
        \App\Models\Blacklists::create(['user_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'number' => '14155552672', 'reason' => 'x']);
        $this->place($other->uid, 'req-key-0010')->assertStatus(403)->assertJsonPath('error.code', 'contact_blocked');
    }

    public function test_a_call_with_an_unknown_transport_outcome_still_reserves_its_slot(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telnyx.com/v2/calls' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->place()->assertStatus(502)->assertJsonPath('data.failure_code', 'transport_error');

        // A new idempotency key must not dial the same contact again while that outcome is unknown.
        $this->place($this->contact->uid, 'req-key-0011')->assertStatus(409)->assertJsonPath('error.code', 'contact_call_in_progress');
        $this->assertSame(1, BusinessVoiceCall::count(), 'The second request must not create or dial another call.');
    }

    public function test_a_number_suspended_after_it_was_first_read_cannot_place_the_call(): void
    {
        $done = false;
        \DB::beforeExecuting(function ($query) use (&$done) {
            if (! $done && str_contains(strtolower($query), 'for update') && str_contains($query, 'business_messaging_numbers')) {
                $done = true; // a renewal suspends the number just before this request takes its lock
                \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update(['status' => 'suspended']);
            }
        });

        $this->place()->assertStatus(409)->assertJsonPath('error.code', 'number_not_voice_ready');

        $this->assertTrue($done, 'The lock query ran, so the race was exercised.');
        $this->noDial();
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    private function selectedScopeStaff(\App\Models\BusinessLocation $grantedLocation): void
    {
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, \App\Enums\Workspace\WorkspaceMembershipRole::Staff, \App\Enums\Workspace\WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => \App\Enums\Workspace\LocationAccessScope::Selected])->save();
        app(\App\Repositories\Contracts\WorkspaceMembershipLocationRepository::class)->assign($membership, $grantedLocation);
        $this->authenticateAs($staff, ['place_voice_calls']);
    }

    private function location(): \App\Models\BusinessLocation
    {
        return \App\Models\BusinessLocation::create(['business_id' => $this->business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    public function test_a_member_scoped_to_other_locations_cannot_call_a_contact_at_an_ungranted_location(): void
    {
        $contactLocation = $this->location();
        $siblingLocation = $this->location();
        $this->contact->forceFill(['location_id' => $contactLocation->id])->save();
        $this->selectedScopeStaff($siblingLocation);

        $this->place()->assertNotFound()->assertJsonPath('error.code', 'contact_not_found');

        $this->noDial();
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    public function test_a_member_with_the_matching_location_grant_can_call_and_read_the_call(): void
    {
        $contactLocation = $this->location();
        $this->contact->forceFill(['location_id' => $contactLocation->id])->save();
        $this->selectedScopeStaff($contactLocation);

        $uid = $this->place()->assertStatus(202)->json('data.id');

        $this->getJson(route('customer.workspaces.businesses.calls.show', [$this->workspace->uid, $this->business->uid, $uid]))->assertOk();
    }

    public function test_a_call_record_for_an_ungranted_location_is_not_readable(): void
    {
        $contactLocation = $this->location();
        $siblingLocation = $this->location();
        $this->contact->forceFill(['location_id' => $contactLocation->id])->save();
        $uid = $this->place()->assertStatus(202)->json('data.id'); // placed by the owner (all locations)

        $this->selectedScopeStaff($siblingLocation);

        $this->getJson(route('customer.workspaces.businesses.calls.show', [$this->workspace->uid, $this->business->uid, $uid]))->assertNotFound();
    }
}
