<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Models\Appointment;
use App\Models\ZoomConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The calendar screens around Zoom: settings page, sub-navigation, meeting location fields, appointment details and the guest page. */
class ZoomCalendarUiTest extends TestCase
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
        Notification::fake();
        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function zoomPage(): string
    {
        return route('customer.workspaces.businesses.calendar.integrations.zoom.show', [$this->workspace->uid, $this->business->uid]);
    }

    private function scope(): array
    {
        return $this->scopeFor($this->locationA);
    }

    public function test_the_zoom_settings_page_offers_connect_and_shows_status(): void
    {
        $this->get($this->zoomPage())->assertOk()
            ->assertSee('data-role="zoom-connect"', false)->assertSee('Connect Zoom')->assertSee('Not connected')
            ->assertSee("never sees your Zoom password");

        $this->connect($this->owner->user);
        $this->get($this->zoomPage())->assertOk()->assertSee('Connected')->assertSee('host@zoom.example')->assertSee('Reconnect Zoom')->assertSee('data-role="zoom-disconnect"', false);

        ZoomConnection::query()->update(['state' => 'revoked']);
        $this->get($this->zoomPage())->assertOk()->assertSee('Needs reconnecting');
    }

    public function test_a_disconnected_or_ineligible_calendar_shows_a_clear_setup_message_not_a_connect_button(): void
    {
        $this->setTier('core');
        $this->get($this->zoomPage())->assertOk()->assertSee('data-role="zoom-not-eligible"', false)->assertDontSee('data-role="zoom-connect"', false);

        $this->setTier('growth');
        config(['zoom.client_id' => null]);
        $this->get($this->zoomPage())->assertOk()->assertSee('data-role="zoom-not-configured"', false)->assertDontSee('data-role="zoom-connect"', false);
    }

    public function test_the_zoom_page_lists_only_this_businesss_hosts(): void
    {
        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $this->connect($otherOwner->user, $otherBusiness->id)->forceFill(['zoom_account_email' => 'other-business@zoom.example'])->save();
        $this->connect($this->host)->forceFill(['zoom_account_email' => 'mine@zoom.example'])->save();

        $this->get($this->zoomPage())->assertOk()->assertSee('mine@zoom.example')->assertDontSee('other-business@zoom.example');
    }

    public function test_the_calendar_navigation_links_to_zoom_only_where_zoom_is_available(): void
    {
        $url = route('customer.workspaces.businesses.calendar.schedule', $this->scope());

        $this->get($url)->assertOk()->assertSee('data-calendar-key="zoom"', false);

        $this->setTier('core');
        $this->get($url)->assertOk()->assertDontSee('data-calendar-key="zoom"', false);
    }

    public function test_the_booking_type_form_offers_meeting_locations_and_zoom_hosts(): void
    {
        $this->connect($this->host);
        $edit = route('customer.workspaces.businesses.calendar.booking-types.edit', array_merge($this->scope(), [$this->type->uid]));

        $this->get($edit)->assertOk()
            ->assertSee('name="meeting_location_type"', false)->assertSee('Zoom meeting')->assertSee('In person')->assertSee('Phone call')->assertSee('Custom meeting link or location')
            ->assertSee('name="zoom_host_connection_id"', false)->assertSee('One shared host');

        $this->setTier('core');
        $this->get($edit)->assertOk()->assertSee('Phone call')->assertDontSee('name="zoom_host_connection_id"', false);
    }

    public function test_the_manual_appointment_form_offers_zoom_only_when_available(): void
    {
        $create = route('customer.workspaces.businesses.calendar.appointments.create', $this->scope());

        $this->get($create)->assertOk()->assertSee('name="meeting_location_type"', false)->assertSee('Zoom meeting');

        $this->setTier('core');
        $this->get($create)->assertOk()->assertSee('Phone call')->assertDontSee('Zoom meeting');
    }

    public function test_the_appointment_details_show_a_join_zoom_button_only_when_there_is_a_valid_link(): void
    {
        $this->connect($this->host);
        $appointment = $this->bookZoom($this->host);
        $show = route('customer.workspaces.businesses.calendar.appointments.show', array_merge($this->scope(), [$appointment->uid]));

        $this->get($show)->assertOk()->assertSee('data-role="join-zoom"', false)->assertSee(self::JOIN_URL, false)->assertDontSee('SECRET-HOST-TOKEN');

        Appointment::query()->whereKey($appointment->id)->update(['zoom_status' => 'pending']);
        $this->get($show)->assertOk()->assertDontSee('data-role="join-zoom"', false)->assertSee('data-role="zoom-pending"', false);
    }

    public function test_a_failed_meeting_shows_a_clear_error_and_a_safe_retry(): void
    {
        $this->connect($this->host);
        $appointment = $this->bookZoom($this->host);
        Appointment::query()->whereKey($appointment->id)->update(['zoom_status' => 'failed', 'zoom_join_url' => null, 'zoom_meeting_id' => null, 'zoom_synced_start_at' => null]);
        $show = route('customer.workspaces.businesses.calendar.appointments.show', array_merge($this->scope(), [$appointment->uid]));

        $this->get($show)->assertOk()->assertSee('data-role="zoom-failed"', false)->assertSee('data-role="retry-zoom"', false)->assertDontSee('data-role="join-zoom"', false);

        $this->post(route('customer.workspaces.businesses.calendar.appointments.zoom-retry', array_merge($this->scope(), [$appointment->uid])))->assertRedirect($show);

        // The retry ran the (sync) job and produced exactly one meeting.
        $this->assertSame('ready', $appointment->fresh()->zoom_status);
        $this->assertSame(1, $this->zoomCalls('POST', '/users/me/meetings') - 1, 'the first booking made one, the retry made one more');
    }

    public function test_non_zoom_appointments_show_no_zoom_controls(): void
    {
        $plain = $this->bookingType(null, ['name' => 'Consultation']);
        $plain->staff()->attach($this->host->id);
        $appointment = $this->bookZoom($this->host, null, $plain);

        $this->get(route('customer.workspaces.businesses.calendar.appointments.show', array_merge($this->scope(), [$appointment->uid])))
            ->assertOk()->assertDontSee('data-role="join-zoom"', false)->assertDontSee('data-role="retry-zoom"', false);
    }

    public function test_a_viewing_agency_cannot_retry_a_clients_zoom_meeting(): void
    {
        $this->assertContains('customer.workspaces.businesses.calendar.appointments.zoom-retry', \App\Library\ViewAs\ViewAsProhibitedActions::EXACT);
    }

    public function test_the_guest_manage_page_shows_the_join_link_and_never_the_host_link(): void
    {
        $this->connect($this->host);
        config(['demo_booking.booking_type_uuid' => $this->type->public_booking_uuid, 'demo_booking.turnstile.site_key' => 's', 'demo_booking.turnstile.secret_key' => 'k']);
        $appointment = $this->bookZoom($this->host);
        $url = \App\Library\Calendar\DemoBooking\DemoBookingManageLinks::urlFor($appointment->fresh());

        $this->assertNotNull($url);
        $this->get($url)->assertOk()->assertSee('data-role="join-zoom"', false)->assertSee(self::JOIN_URL, false)->assertDontSee('SECRET-HOST-TOKEN')->assertDontSee('zak=', false);
    }
}
