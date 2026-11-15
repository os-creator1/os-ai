<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * The "Book appointment" screen: contact preselection, the configured and the
 * no-types states, real availability, summary data and the booking itself.
 *
 * Time is pinned to the last week of February 2027 so that Monday 2027-03-01 —
 * the day every Calendar fixture gives staff hours on — is inside the type's
 * 30-day booking window and in the future.
 */
class BookAppointmentScreenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    private const MONDAY = '2027-03-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
        DB::table('businesses')->where('id', $this->business->id)->update(['timezone' => 'America/New_York']);
        $this->business = $this->business->fresh();
        Carbon::setTestNow(Carbon::parse('2027-02-25 12:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scope(): array
    {
        return $this->scopeFor($this->locationA);
    }

    private function createUrl(array $query = []): string
    {
        return $this->calendarUrl('appointments.create', $this->scope()) . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function contactUid(int $id): string
    {
        return (string) DB::table('contacts')->where('id', $id)->value('uid');
    }

    /** A named contact, so the card shows a real name and a formatted phone. */
    private function namedContact(string $first = 'Jordan', string $last = 'Ellis', string $phone = '16016926705'): int
    {
        $id = $this->contactId();
        $groupId = (int) DB::table('contacts')->where('id', $id)->value('group_id');
        DB::table('contacts')->where('id', $id)->update(['phone' => $phone]);
        $this->nameContact($id, $groupId, $first, $last);

        return $id;
    }

    private function nameContact(int $contactId, int $groupId, string $first, string $last): void
    {
        foreach (['FIRST_NAME' => $first, 'LAST_NAME' => $last] as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $groupId, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );

            ContactsCustomField::create(['contact_id' => $contactId, 'field_id' => $field->id, 'value' => $value]);
        }
    }

    /** @return array{0: BookingType, 1: \App\Models\User} an active type whose pool is one staff member with Monday hours */
    private function bookableType(array $overrides = []): array
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType(null, $overrides + ['duration_minutes' => 30]);
        $type->staff()->attach($staff->id);

        return [$type, $staff];
    }

    // -----------------------------------------------------------------
    // A. Selected contact
    // -----------------------------------------------------------------

    public function test_a_contact_uid_preselects_the_customer_and_renders_the_card(): void
    {
        $this->bookableType();
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY]))->assertOk()->getContent();

        $this->assertStringContainsString('data-section="selected-contact"', $html);
        $this->assertStringContainsString('Appointment for', $html);
        $this->assertStringContainsString('Jordan Ellis', $html);
        $this->assertStringContainsString('+1 (601) 692-6705', $html);
        $this->assertStringContainsString('name="contact_uid" value="' . $uid . '"', $html);
        $this->assertStringNotContainsString('data-section="contact-search"', $html, 'A preselected customer must not be asked for again.');
        $this->assertStringContainsString('data-role="change-contact"', $html);
        $this->assertStringContainsString('Booking Group', $html, 'The contact group is part of the card.');
    }

    public function test_change_opens_the_customer_selector_and_a_search_can_pick_another_contact(): void
    {
        $this->bookableType();
        $chosen = $this->namedContact('Priya', 'Natarajan', '14155550111');
        $uid = $this->contactUid($chosen);

        $selector = $this->get($this->createUrl(['change' => 1, 'contact' => 'whatever']))->assertOk()->getContent();
        $this->assertStringContainsString('data-section="contact-search"', $selector);
        $this->assertStringNotContainsString('data-section="selected-contact"', $selector);

        $results = $this->get($this->createUrl(['q' => 'Natarajan']))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="contact-results"', $results);
        $this->assertStringContainsString('contact=' . $uid, $results, 'Each result selects that contact.');
    }

    public function test_opening_the_screen_without_a_contact_still_supports_searching(): void
    {
        $this->bookableType();

        $html = $this->get($this->createUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-section="contact-search"', $html);
        $this->assertStringNotContainsString('data-section="appointment-form"', $html);
    }

    public function test_the_contact_survives_a_validation_error(): void
    {
        [$type] = $this->bookableType();
        $uid = $this->contactUid($this->namedContact());

        $this->from($this->createUrl(['contact' => $uid]))
            ->post($this->calendarUrl('appointments.store', $this->scope()), [
                'booking_type_uid' => $type->uid, 'contact_uid' => $uid, 'staff' => 'auto',
                'date' => 'garbage', 'time' => '10:00',
            ])->assertSessionHasErrors('date');

        // Back on the form (no `contact` in the URL any more) the old input keeps the customer selected.
        $html = $this->get($this->createUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-section="selected-contact"', $html);
        $this->assertStringContainsString('Jordan Ellis', $html);
    }

    // -----------------------------------------------------------------
    // Tenant isolation and Location restrictions
    // -----------------------------------------------------------------

    public function test_an_unknown_contact_uid_is_a_404(): void
    {
        $this->bookableType();

        $this->get($this->createUrl(['contact' => 'not-a-real-uid']))->assertNotFound();
    }

    public function test_a_sibling_locations_contact_is_a_404_and_never_leaks(): void
    {
        $this->bookableType();
        $atB = $this->namedContact('Sibling', 'Person', '14155550112');
        DB::table('contacts')->where('id', $atB)->update(['location_id' => $this->locationB->id]);

        $this->get($this->createUrl(['contact' => $this->contactUid($atB)]))->assertNotFound();
    }

    public function test_another_businesses_contact_is_a_404(): void
    {
        $this->bookableType();
        [$foreignBusiness] = $this->foreignBusinessWithLocation();
        $foreign = $this->foreignContact($foreignBusiness);

        $this->get($this->createUrl(['contact' => $foreign]))->assertNotFound();
    }

    private function foreignContact(Business $business): string
    {
        $group = \App\Models\ContactGroups::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'name' => 'Foreign group', 'status' => true,
        ]);
        $uid = (string) \Illuminate\Support\Str::uuid();
        DB::table('contacts')->insert([
            'uid' => $uid, 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => null, 'group_id' => $group->id, 'phone' => '14155559999',
            'status' => \App\Models\Contacts::STATUS_SUBSCRIBE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uid;
    }

    public function test_the_store_endpoint_still_refuses_a_sibling_locations_contact(): void
    {
        [$type] = $this->bookableType();
        $atB = $this->contactId();
        DB::table('contacts')->where('id', $atB)->update(['location_id' => $this->locationB->id]);

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid, 'contact_uid' => $this->contactUid($atB), 'staff' => 'auto',
            'date' => self::MONDAY, 'time' => '10:00',
        ])->assertNotFound();
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_the_slots_endpoint_is_gated_like_every_other_route(): void
    {
        [$type] = $this->bookableType();
        $query = ['booking_type_uid' => $type->uid, 'staff' => 'auto', 'date' => self::MONDAY];

        // Another Location of the same Business: the type does not belong there.
        $this->getJson($this->calendarUrl('appointments.slots', $this->scopeFor($this->locationB)) . '?' . http_build_query($query))
            ->assertNotFound();

        $this->actingAs($this->outsider);
        $this->getJson($this->calendarUrl('appointments.slots', $this->scope()) . '?' . http_build_query($query))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // C. No appointment types
    // -----------------------------------------------------------------

    public function test_with_no_types_the_empty_state_shows_and_no_booking_form_renders(): void
    {
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-section="selected-contact"', $html);
        $this->assertStringContainsString('data-section="no-booking-types"', $html);
        $this->assertStringContainsString('No appointment types yet', $html);
        $this->assertStringContainsString('Create appointment type', $html);
        $this->assertStringContainsString('Jordan is selected. Add an appointment type to start booking.', $html);
        $this->assertStringNotContainsString('data-section="appointment-form"', $html);
        $this->assertStringNotContainsString('data-role="book-appointment"', $html);
        $this->assertStringContainsString('contact=' . $uid, $html, 'The create-type link carries the customer.');
    }

    public function test_an_inactive_type_does_not_count_as_a_configured_state(): void
    {
        $this->bookingType(null, ['is_active' => false]);
        $uid = $this->contactUid($this->namedContact());

        $this->get($this->createUrl(['contact' => $uid]))->assertOk()->assertSee('data-section="no-booking-types"', false);
    }

    public function test_appointment_type_setup_keeps_the_customer_and_leads_back_to_booking(): void
    {
        $uid = $this->contactUid($this->namedContact());

        $create = $this->get($this->calendarUrl('booking-types.create', $this->scope()) . '?contact=' . $uid)->assertOk()->getContent();
        $this->assertStringContainsString('name="contact" value="' . $uid . '"', $create);

        $response = $this->post($this->calendarUrl('booking-types.store', $this->scope()), [
            'contact' => $uid, 'name' => 'Discovery call', 'duration_minutes' => 30,
        ]);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('contact=' . $uid, $location);

        $edit = $this->get($location)->assertOk()->getContent();
        $this->assertStringContainsString('data-role="return-to-booking"', $edit);
        $this->assertStringContainsString('go back to booking Jordan Ellis', $edit);
        $this->assertStringContainsString($this->createUrl(['contact' => $uid]), html_entity_decode($edit));
    }

    public function test_type_setup_ignores_a_contact_it_may_not_book(): void
    {
        $atB = $this->contactId();
        DB::table('contacts')->where('id', $atB)->update(['location_id' => $this->locationB->id]);

        $html = $this->get($this->calendarUrl('booking-types.create', $this->scope()) . '?contact=' . $this->contactUid($atB))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="contact"', $html);
    }

    // -----------------------------------------------------------------
    // B. Configured screen, real availability, summary, booking
    // -----------------------------------------------------------------

    public function test_the_configured_screen_lists_real_types_staff_and_only_real_times(): void
    {
        [$type, $staff] = $this->bookableType(['name' => 'Booth consultation']);
        $this->bookingType(null, ['name' => 'Quick follow-up', 'duration_minutes' => 15]);
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY]))->assertOk()->getContent();

        $this->assertStringContainsString('data-section="appointment-form"', $html);
        $this->assertStringContainsString('Booth consultation', $html);
        $this->assertStringContainsString('30 min', $html);
        $this->assertStringContainsString('Quick follow-up', $html);
        $this->assertStringContainsString('value="' . $staff->id . '"', $html);
        // Monday 08:00–20:00 in 30-minute steps: 8:00 AM is offered, 7:30 AM and 8:00 PM are not.
        $this->assertStringContainsString('data-time="08:00"', $html);
        $this->assertStringContainsString('data-time="19:30"', $html);
        $this->assertStringNotContainsString('data-time="07:30"', $html);
        $this->assertStringNotContainsString('data-time="20:00"', $html);
        $this->assertStringNotContainsString('Jordan gets a confirmation text', $html);
    }

    public function test_a_day_without_hours_offers_no_times_and_is_disabled(): void
    {
        $this->bookableType();
        $uid = $this->contactUid($this->namedContact());

        // Tuesday has no availability rule.
        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => '2027-03-02']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-time=', $html);
        $this->assertStringContainsString('No open times on this day', $html);
        $this->assertMatchesRegularExpression('/data-date="2027-03-03"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-date="2027-03-01"[^>]*disabled/', $html, 'Monday has hours, so it stays selectable.');
    }

    public function test_a_type_with_nobody_assigned_says_so_and_offers_nothing(): void
    {
        $this->bookingType(null, ['name' => 'Unstaffed']);
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-time=', $html);
        $this->assertStringContainsString('Nobody is assigned', $html);
        $this->assertMatchesRegularExpression('/data-role="book-appointment"[^>]*disabled/', $html);
    }

    public function test_a_booked_slot_is_no_longer_offered(): void
    {
        [$type, $staff] = $this->bookableType();
        $contactId = $this->namedContact();
        $this->engine()->book($type, (int) $staff->id, $contactId, $this->slotStart('10:00:00'));

        $html = $this->get($this->createUrl(['contact' => $this->contactUid($contactId), 'date' => self::MONDAY]))->getContent();

        $this->assertStringNotContainsString('data-time="10:00"', $html);
        $this->assertStringContainsString('data-time="10:30"', $html);
    }

    public function test_the_slots_endpoint_returns_canonical_days_and_times_for_the_chosen_staff(): void
    {
        [$type, $staff] = $this->bookableType();
        $other = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff); // eligible, but no hours

        $auto = $this->getJson($this->calendarUrl('appointments.slots', $this->scope()) . '?' . http_build_query([
            'booking_type_uid' => $type->uid, 'staff' => 'auto', 'date' => self::MONDAY,
        ]))->assertOk()->assertHeader('Cache-Control')->json();

        $this->assertTrue($auto['has_staff']);
        $this->assertSame('08:00', $auto['slots'][0]['time']);
        $this->assertSame('8:00 AM', $auto['slots'][0]['label']);
        $this->assertCount(7, $auto['days']);
        $this->assertSame(self::MONDAY, $auto['days'][0]['date']);
        $this->assertTrue($auto['days'][0]['available']);
        $this->assertFalse($auto['days'][1]['available']);
        $this->assertSame('America/New_York', $auto['timezone']);

        $none = $this->getJson($this->calendarUrl('appointments.slots', $this->scope()) . '?' . http_build_query([
            'booking_type_uid' => $type->uid, 'staff' => (string) $other->id, 'date' => self::MONDAY,
        ]))->assertOk()->json();
        $this->assertSame([], $none['slots'], 'A member with no working hours is offered no times.');

        $unknown = $this->getJson($this->calendarUrl('appointments.slots', $this->scope()) . '?' . http_build_query([
            'booking_type_uid' => 'nope', 'staff' => 'auto', 'date' => self::MONDAY,
        ]))->assertNotFound();
    }

    public function test_the_slots_endpoint_validates_its_input(): void
    {
        [$type] = $this->bookableType();

        $this->getJson($this->calendarUrl('appointments.slots', $this->scope()) . '?' . http_build_query([
            'booking_type_uid' => $type->uid, 'staff' => 'auto', 'date' => 'tomorrow',
        ]))->assertStatus(422);
    }

    public function test_summary_values_reflect_the_selection_and_meeting_location(): void
    {
        [$type, $staff] = $this->bookableType(['name' => 'Booth consultation', 'meeting_location_type' => 'phone']);
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY, 'time' => '10:00', 'staff' => (string) $staff->id]))->getContent();

        $this->assertStringContainsString('Mon, Mar 1 · 10:00 AM', $html);
        $this->assertStringContainsString('Booth consultation · 30 min', $html);
        $this->assertStringContainsString('Phone call', $html);
        $this->assertStringContainsString('New York (EST)', $html);
        $this->assertMatchesRegularExpression('/name="time" value="10:00"/', $html);
    }

    public function test_no_confirmation_text_checkbox_and_no_note_field_are_offered(): void
    {
        $this->bookableType();
        $uid = $this->contactUid($this->namedContact());

        $html = $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY]))->getContent();

        // Staff bookings have no text-confirmation sending path, and appointments have no note column.
        $this->assertStringNotContainsString('Text Jordan a confirmation', $html);
        $this->assertStringNotContainsString('name="note"', $html);
        $this->assertStringNotContainsString('name="send_sms"', $html);
    }

    public function test_booking_from_the_preselected_screen_creates_the_appointment_through_the_engine(): void
    {
        [$type, $staff] = $this->bookableType();
        $contactId = $this->namedContact();

        $response = $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid, 'contact_uid' => $this->contactUid($contactId), 'staff' => 'auto',
            'date' => self::MONDAY, 'time' => '10:00',
        ]);

        $appointment = Appointment::query()->firstOrFail();
        $response->assertRedirect($this->calendarUrl('appointments.show', array_merge($this->scope(), [$appointment->uid])));
        $this->assertSame($contactId, (int) $appointment->contact_id);
        $this->assertSame((int) $staff->id, (int) $appointment->staff_user_id);
        $this->assertSame('2027-03-01 15:00:00', $appointment->start_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_server_still_refuses_a_time_that_is_not_available(): void
    {
        [$type] = $this->bookableType();
        $contactId = $this->namedContact();

        $this->from($this->createUrl())->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid, 'contact_uid' => $this->contactUid($contactId), 'staff' => 'auto',
            'date' => '2027-03-02', 'time' => '10:00',
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_the_zoom_and_location_override_controls_are_still_available(): void
    {
        $this->bookableType();
        $uid = $this->contactUid($this->namedContact());

        $this->get($this->createUrl(['contact' => $uid, 'date' => self::MONDAY]))
            ->assertSee('name="meeting_location_type"', false)
            ->assertSee('Phone call');
    }

    // -----------------------------------------------------------------
    // The Contact profile's "Book appointment" action
    // -----------------------------------------------------------------

    public function test_the_contact_profile_links_to_booking_with_the_contact_uid_not_a_search(): void
    {
        $this->bookableType();
        $uid = $this->contactUid($this->namedContact());
        $this->withSession(['permissions' => collect(['access_backend', 'view_contact'])]);

        $html = $this->get(route('customer.workspaces.businesses.people.show', [$this->workspace->uid, $this->business->uid, $uid]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="contact-book"/', $html);
        $expected = $this->createUrl(['contact' => $uid]);
        $this->assertStringContainsString(htmlspecialchars($expected, ENT_QUOTES), $html);
        $this->assertStringNotContainsString('appointments/new?q=', $html);
    }
}
