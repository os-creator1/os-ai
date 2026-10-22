<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomClient;
use App\Library\Calendar\Zoom\ZoomConnectionManager;
use App\Library\Calendar\Zoom\ZoomEligibility;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Models\Appointment;
use App\Models\ZoomConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zoom OAuth connection, token refresh, tenant isolation, plan eligibility and the HTTP surface around them.
 */
class ZoomConnectionTest extends TestCase
{
    use RefreshDatabase;
    use ZoomTestSupport;

    private $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootZoom();
        $this->host = $this->bookableStaff();
        $this->type->staff()->attach($this->host->id);
        $this->fakeZoom();
        $this->entitledCalendar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(): ZoomConnectionManager
    {
        return app(ZoomConnectionManager::class);
    }

    private function stateFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q['state'];
    }

    // ---------------------------------------------------------------- OAuth

    public function test_connecting_uses_the_zoom_authorization_page_and_never_asks_for_a_password_or_token(): void
    {
        $url = $this->manager()->begin($this->business, (int) $this->owner->user_id);

        $this->assertStringStartsWith('https://zoom.us/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('zoom-client-id', $q['client_id']);
        $this->assertSame('https://app.example.test/zoom/oauth/callback', $q['redirect_uri']);
        $this->assertStringNotContainsString('zoom-client-secret', $url);
        $this->assertSame('pending', ZoomConnection::query()->firstOrFail()->state);
    }

    public function test_the_callback_stores_encrypted_tokens_and_the_connected_account(): void
    {
        $state = $this->stateFrom($this->manager()->begin($this->business, (int) $this->owner->user_id));

        $connection = $this->manager()->complete('auth-code', $state, $this->owner->user);

        $this->assertSame('active', $connection->state);
        $this->assertSame('host@zoom.example', $connection->zoom_account_email);
        $this->assertSame('Hal Host', $connection->zoom_display_name);

        $raw = DB::table('zoom_connections')->first();
        $this->assertStringNotContainsString('access-token-NEW', $raw->access_token_encrypted);
        $this->assertStringNotContainsString('refresh-token-NEW', $raw->refresh_token_encrypted);
        $this->assertSame('refresh-token-NEW', $connection->fresh()->refresh_token_encrypted);
        $this->assertArrayNotHasKey('refresh_token_encrypted', $connection->toArray());

        Http::assertSent(fn ($r) => $r->url() === 'https://zoom.us/oauth/token' && $r->hasHeader('Authorization') && $r['grant_type'] === 'authorization_code' && $r['code'] === 'auth-code');
    }

    public function test_a_state_cannot_be_replayed_forged_expired_or_used_by_someone_else(): void
    {
        $state = $this->stateFrom($this->manager()->begin($this->business, (int) $this->owner->user_id));

        // someone else
        $this->assertNull($this->manager()->complete('c', $state, $this->host));
        // forged
        $this->assertNull($this->manager()->complete('c', 'not-a-real-state', $this->owner->user));
        // first use works, replay does not
        $this->assertNotNull($this->manager()->complete('c', $state, $this->owner->user));
        $this->assertNull($this->manager()->complete('c', $state, $this->owner->user));

        // expired
        $state2 = $this->stateFrom($this->manager()->begin($this->business, (int) $this->owner->user_id));
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertNull($this->manager()->complete('c', $state2, $this->owner->user));
    }

    public function test_someone_without_access_to_the_business_cannot_complete_a_connection(): void
    {
        $outsider = $this->createCustomer()->user;
        ZoomConnection::query()->create(['business_id' => $this->business->id, 'user_id' => $outsider->id, 'state' => 'pending', 'state_nonce' => hash('sha256', 'n')]);
        $state = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['b' => $this->business->id, 'u' => $outsider->id, 'n' => 'n', 'e' => time() + 600]));

        $this->assertNull($this->manager()->complete('c', $state, $outsider));
    }

    public function test_a_failed_token_exchange_connects_nothing(): void
    {
        $this->fakeZoom(['https://zoom.us/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $state = $this->stateFrom($this->manager()->begin($this->business, (int) $this->owner->user_id));

        $this->assertNull($this->manager()->complete('bad', $state, $this->owner->user));
        $this->assertNotSame('active', ZoomConnection::query()->firstOrFail()->state);
    }

    // ---------------------------------------------------------------- tokens

    public function test_an_expired_access_token_is_refreshed_and_the_rotated_refresh_token_is_saved(): void
    {
        $connection = $this->connect($this->host);
        $connection->forceFill(['access_expires_at' => now()->subMinute()])->save();

        $token = $this->manager()->accessToken($connection);

        $this->assertSame('access-token-NEW', $token);
        $this->assertSame('refresh-token-NEW', $connection->fresh()->refresh_token_encrypted);
        Http::assertSent(fn ($r) => $r->url() === 'https://zoom.us/oauth/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-token-A');

        // Fresh now: no second refresh.
        $this->assertSame('access-token-NEW', $this->manager()->accessToken($connection->fresh()));
        $this->assertSame(1, $this->zoomCalls('POST', 'oauth/token'));
    }

    public function test_a_revoked_refresh_token_marks_the_connection_revoked_and_requires_reconnecting(): void
    {
        $connection = $this->connect($this->host);
        $connection->forceFill(['access_expires_at' => now()->subMinute()])->save();
        $this->fakeZoom(['https://zoom.us/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->manager()->accessToken($connection);
            $this->fail('expected an auth failure');
        } catch (ZoomApiException $e) {
            $this->assertSame('auth', $e->kind);
            $this->assertFalse($e->retryable());
        }

        $fresh = $connection->fresh();
        $this->assertSame('revoked', $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertFalse($fresh->isActive());
    }

    public function test_disconnecting_wipes_the_stored_credentials(): void
    {
        $connection = $this->connect($this->host);

        $this->manager()->disconnect($connection);

        $raw = DB::table('zoom_connections')->first();
        $this->assertNull($raw->access_token_encrypted);
        $this->assertNull($raw->refresh_token_encrypted);
        $this->assertSame('revoked', $raw->state);
    }

    public function test_the_feature_is_inert_until_zoom_is_configured(): void
    {
        config(['zoom.client_secret' => null]);

        $this->assertFalse(app(ZoomClient::class)->isConfigured());
        $this->assertFalse($this->manager()->isEnabledFor($this->business));

        config(['zoom.client_secret' => 's', 'zoom.redirect' => 'http://insecure.example/zoom/oauth/callback']);
        $this->assertFalse(app(ZoomClient::class)->isConfigured(), 'the redirect must be https');
    }

    // ---------------------------------------------------------- eligibility

    public function test_only_growth_and_agency_plans_are_eligible_and_nothing_in_billing_changes(): void
    {
        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->first();

        foreach (['core' => false, 'growth' => true, 'agency' => true] as $tier => $allowed) {
            $this->setTier($tier);
            $this->assertSame($allowed, app(ZoomEligibility::class)->allows($this->business->fresh()), $tier);
        }

        $this->setTier('growth');
        $this->assertSame($assignment->status, DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->value('status'));
        $this->assertSame(0, DB::table('workspace_plan_catalog')->whereNotNull('provider_price_id')->whereNull('price')->count());
    }

    public function test_core_cannot_start_a_connection(): void
    {
        $this->setTier('core');

        $this->expectException(ZoomApiException::class);
        $this->manager()->begin($this->business, (int) $this->owner->user_id);
    }

    // ----------------------------------------------------------- isolation

    public function test_connections_are_isolated_per_business_and_per_user(): void
    {
        $mine = $this->connect($this->host);

        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $theirs = $this->connect($this->host, $otherBusiness->id, 'their-token');

        // The same person connected to two Businesses has two independent connections.
        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame($mine->id, $this->manager()->activeFor($this->business->id, $this->host->id)->id);
        $this->assertNull($this->manager()->activeInBusiness($theirs->id, $this->business->id), "another Business's connection is not reachable");
        $this->assertNull($this->manager()->activeFor($this->business->id, $otherOwner->user_id));
    }

    public function test_an_owner_cannot_disconnect_another_businesses_connection_over_http(): void
    {
        $this->authenticate($this->owner->user);
        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $theirs = $this->connect($otherOwner->user, $otherBusiness->id);

        $this->post(route('customer.workspaces.businesses.calendar.integrations.zoom.disconnect', [$this->workspace->uid, $this->business->uid, $theirs->id]))
            ->assertNotFound();

        $this->assertSame('active', $theirs->fresh()->state);
    }

    public function test_only_the_owner_or_the_host_may_disconnect_a_host(): void
    {
        $connection = $this->connect($this->host);

        $this->authenticate($this->owner->user);
        $this->post(route('customer.workspaces.businesses.calendar.integrations.zoom.disconnect', [$this->workspace->uid, $this->business->uid, $connection->id]))
            ->assertRedirect();
        $this->assertSame('revoked', $connection->fresh()->state, 'the Workspace owner may disconnect a host');
    }

    public function test_the_oauth_callback_with_a_bad_state_connects_nothing(): void
    {
        $this->authenticate($this->owner->user);

        $this->get(route('customer.zoom.oauth.callback', ['code' => 'x', 'state' => 'forged']))->assertRedirect();
        $this->get(route('customer.zoom.oauth.callback', ['error' => 'access_denied']))->assertRedirect();

        $this->assertSame(0, ZoomConnection::query()->where('state', 'active')->count());
    }

    public function test_view_as_cannot_connect_disconnect_or_retry_a_clients_zoom(): void
    {
        $names = ViewAsProhibitedActions::EXACT;

        foreach (['connect', 'disconnect'] as $action) {
            $this->assertContains('customer.workspaces.businesses.calendar.integrations.zoom.'.$action, $names);
        }
        $this->assertContains('customer.zoom.oauth.callback', $names);
        $this->assertContains('customer.workspaces.businesses.calendar.appointments.zoom-retry', $names);
    }

    // ------------------------------------------------- booking-type settings

    public function test_zoom_can_be_chosen_as_a_booking_types_default_only_where_the_plan_allows_it(): void
    {
        $this->authenticate($this->owner->user);
        $url = route('customer.workspaces.businesses.calendar.booking-types.update', array_merge($this->scopeFor($this->locationA), [$this->type->uid]));
        $payload = ['name' => 'Demo', 'duration_minutes' => 30, 'meeting_location_type' => 'zoom'];

        $this->setTier('core');
        // The app answers refused plan features the same way as "not there".
        $this->post($url, $payload)->assertNotFound();

        $this->setTier('growth');
        $this->post($url, $payload)->assertRedirect();
        $this->assertSame('zoom', $this->type->fresh()->meeting_location_type);

        // Not forced: switching back keeps ordinary behaviour.
        $this->post($url, ['meeting_location_type' => 'in_person'] + $payload)->assertRedirect();
        $this->assertSame('in_person', $this->type->fresh()->meeting_location_type);
        $this->post($url, ['meeting_location_type' => ''] + $payload)->assertRedirect();
        $this->assertNull($this->type->fresh()->meeting_location_type);
    }

    public function test_a_shared_host_chosen_in_the_form_must_belong_to_the_business(): void
    {
        $this->authenticate($this->owner->user);
        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $foreign = $this->connect($otherOwner->user, $otherBusiness->id);
        $own = $this->connect($this->host);
        $url = route('customer.workspaces.businesses.calendar.booking-types.update', array_merge($this->scopeFor($this->locationA), [$this->type->uid]));
        $base = ['name' => 'Demo', 'duration_minutes' => 30, 'meeting_location_type' => 'zoom'];

        $this->post($url, $base + ['zoom_host_connection_id' => $foreign->id])->assertRedirect();
        $this->assertNull($this->type->fresh()->zoom_host_connection_id);

        $this->post($url, $base + ['zoom_host_connection_id' => $own->id])->assertRedirect();
        $this->assertSame($own->id, $this->type->fresh()->zoom_host_connection_id);
    }

    public function test_a_custom_location_requires_its_text_and_other_types_discard_it(): void
    {
        $this->authenticate($this->owner->user);
        $url = route('customer.workspaces.businesses.calendar.booking-types.update', array_merge($this->scopeFor($this->locationA), [$this->type->uid]));

        $this->post($url, ['name' => 'Demo', 'duration_minutes' => 30, 'meeting_location_type' => 'custom'])->assertSessionHasErrors('meeting_location_custom');

        $this->post($url, ['name' => 'Demo', 'duration_minutes' => 30, 'meeting_location_type' => 'custom', 'meeting_location_custom' => 'https://meet.example/room'])->assertRedirect();
        $this->assertSame('https://meet.example/room', $this->type->fresh()->meeting_location_custom);

        $this->post($url, ['name' => 'Demo', 'duration_minutes' => 30, 'meeting_location_type' => 'phone', 'meeting_location_custom' => 'ignored'])->assertRedirect();
        $this->assertNull($this->type->fresh()->meeting_location_custom);
    }

    // ------------------------------------------------- manual appointments

    public function test_a_manual_appointment_can_use_zoom_and_gets_its_own_meeting(): void
    {
        $this->authenticate($this->owner->user);
        $this->connect($this->host);
        $plain = $this->bookingType(null, ['name' => 'Consultation']);
        $plain->staff()->attach($this->host->id);
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post(route('customer.workspaces.businesses.calendar.appointments.store', $this->scopeFor($this->locationA)), [
            'booking_type_uid' => $plain->uid, 'contact_uid' => $contactUid, 'staff' => (string) $this->host->id,
            'date' => '2027-03-01', 'time' => '10:00', 'meeting_location_type' => 'zoom',
        ])->assertRedirect();

        $appointment = Appointment::query()->latest('id')->firstOrFail();
        $this->assertSame('zoom', $appointment->meeting_location_type);
        $this->assertSame('ready', $appointment->zoom_status);
        $this->assertSame(self::JOIN_URL, $appointment->zoom_join_url);
        $this->assertNull($plain->fresh()->meeting_location_type, 'the booking type itself is not changed');
    }

    public function test_a_manual_zoom_appointment_is_refused_with_a_clear_message_when_the_host_is_not_connected(): void
    {
        $this->authenticate($this->owner->user);
        $plain = $this->bookingType(null, ['name' => 'Consultation']);
        $plain->staff()->attach($this->host->id);
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post(route('customer.workspaces.businesses.calendar.appointments.store', $this->scopeFor($this->locationA)), [
            'booking_type_uid' => $plain->uid, 'contact_uid' => $contactUid, 'staff' => (string) $this->host->id,
            'date' => '2027-03-01', 'time' => '10:00', 'meeting_location_type' => 'zoom',
        ])->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'Connect Zoom'));

        $this->assertSame(0, Appointment::query()->count());
    }

    // ------------------------------------------------------- public booking

    public function test_a_public_zoom_booking_page_exists_only_while_its_host_is_connected(): void
    {
        $url = route('public.booking.show', $this->type->public_booking_uuid);

        $this->get($url)->assertNotFound();

        $connection = $this->connect($this->host);
        $this->get($url)->assertOk();

        $this->manager()->disconnect($connection);
        $this->get($url)->assertNotFound();
    }

    public function test_the_booking_types_readiness_explains_the_zoom_setup_requirement(): void
    {
        $readiness = app(\App\Library\Calendar\BookingTypeManager::class)->publicBookingReadiness($this->type);

        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('Zoom', (string) $readiness['reason']);
    }
}
