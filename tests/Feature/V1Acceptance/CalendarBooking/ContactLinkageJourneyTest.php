<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Contacts;
use App\Models\StaffAvailabilityRule;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance journey H — what a public booking does to Contacts. The rule under
 * test is Contract 15 §5.8: identity is the PHONE WITHIN THE BOOKED LOCATION.
 * Never Business-wide (a sibling Location is a different Contact, by MUST in
 * Addendum §5) and never cross-Business.
 */
class ContactLinkageJourneyTest extends CalendarJourneyTestCase
{
    private BookingType $typeA;

    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = $this->bookableStaff();
        $this->staffId = (int) $staff->id;
        $this->typeA = $this->typeWithStaff($this->locationA, $staff);
    }

    private function bookAt(BookingType $type, string $time, string $phone, array $extra = []): void
    {
        $this->from($this->publicShow($type))
            ->post($this->publicStore($type), $this->guest(['time' => $time, 'phone' => $phone] + $extra))
            ->assertRedirect(route('public.booking.confirmed', [$type->public_booking_uuid]));
    }

    private function contactsWithPhone(string $phone, ?int $businessId = null)
    {
        return DB::table('contacts')->where('phone', $phone)
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))->get();
    }

    public function test_a_first_booking_creates_one_location_attributed_contact_with_the_guests_name(): void
    {
        $this->bookAt($this->typeA, '10:00', '+1 (415) 555-0100', ['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $contacts = $this->contactsWithPhone('14155550100');
        $this->assertCount(1, $contacts);
        $contact = $contacts->first();
        $this->assertSame((int) $this->locationA->id, (int) $contact->location_id);
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame(Contacts::STATUS_SUBSCRIBE, $contact->status);

        $names = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)->pluck('v.value', 'f.tag')->all();
        $this->assertSame('Grace', $names['FIRST_NAME'] ?? null);
        $this->assertSame('Hopper', $names['LAST_NAME'] ?? null);

        // It joined the Business's own contact group, and the appointment points at THIS contact.
        $group = DB::table('contact_groups')->where('id', $contact->group_id)->first();
        $this->assertSame((int) $this->business->id, (int) $group->business_id);
        $this->assertSame((int) $contact->id, (int) Appointment::query()->firstOrFail()->contact_id);
    }

    public function test_repeat_bookings_by_the_same_person_reuse_one_contact_however_the_phone_is_typed(): void
    {
        $this->bookAt($this->typeA, '09:00', '+1 (415) 555-0101');
        $this->bookAt($this->typeA, '11:00', '1-415-555-0101');
        $this->bookAt($this->typeA, '13:00', '14155550101');

        $this->assertCount(1, $this->contactsWithPhone('14155550101'));
        $this->assertSame(3, $this->appointmentCount());
        $this->assertSame(1, Appointment::query()->distinct()->count('contact_id'));
    }

    public function test_a_contact_already_known_at_the_location_is_reused_not_duplicated(): void
    {
        $existingId = $this->contactId(); // a CRM-known Contact at Location A
        DB::table('contacts')->where('id', $existingId)->update(['phone' => '14155550102']);
        $before = $this->contactCount();

        $this->bookAt($this->typeA, '10:00', '(415) 555-0102'); // normalises to 4155550102, a DIFFERENT stored form
        $this->assertSame($before + 1, $this->contactCount(), 'the stored form is the identity key, so a differently-typed number is a new person');

        $this->bookAt($this->typeA, '12:00', '+1 415 555 0102'); // normalises to 14155550102
        $this->assertSame($before + 1, $this->contactCount(), 'the stored form matches the existing Contact: no duplicate');
        $this->assertSame(
            $existingId,
            (int) Appointment::query()->orderByDesc('id')->firstOrFail()->contact_id
        );
    }

    public function test_the_same_person_at_a_sibling_location_is_a_separate_contact_by_contract(): void
    {
        $other = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $this->giveMondayAvailability((int) $other->id, $this->locationB);
        $typeB = $this->typeWithStaff($this->locationB, $other);

        $this->bookAt($this->typeA, '10:00', '+1 (415) 555-0103');
        $this->bookAt($typeB, '10:00', '+1 (415) 555-0103');

        $contacts = $this->contactsWithPhone('14155550103', (int) $this->business->id);
        $this->assertCount(2, $contacts);
        $this->assertEqualsCanonicalizing(
            [(int) $this->locationA->id, (int) $this->locationB->id],
            $contacts->pluck('location_id')->map(fn ($i): int => (int) $i)->all()
        );
        foreach (Appointment::query()->get() as $appointment) {
            $contact = DB::table('contacts')->where('id', $appointment->contact_id)->first();
            $this->assertSame((int) $appointment->business_location_id, (int) $contact->location_id, 'an appointment never points at another Location\'s Contact');
        }
    }

    public function test_a_contact_of_another_business_is_never_reused(): void
    {
        [$foreignBusiness, $foreignLocation, $foreignOwner] = $this->foreignBusinessWithLocation();
        DB::table('businesses')->where('id', $foreignBusiness->id)->update(['status' => BusinessStatus::Active->value]);

        // The foreign Business already knows this phone, AT ITS OWN Location.
        $group = \App\Models\ContactGroups::create([
            'customer_id' => $foreignBusiness->customer_id, 'business_id' => $foreignBusiness->id,
            'name' => 'Theirs', 'status' => true,
        ]);
        $foreignContactId = DB::table('contacts')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $foreignBusiness->customer_id,
            'business_id' => $foreignBusiness->id, 'location_id' => $foreignLocation->id, 'group_id' => $group->id,
            'phone' => '14155550104', 'status' => Contacts::STATUS_SUBSCRIBE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->bookAt($this->typeA, '10:00', '+1 (415) 555-0104');

        $mine = $this->contactsWithPhone('14155550104', (int) $this->business->id);
        $this->assertCount(1, $mine);
        $this->assertNotSame($foreignContactId, (int) $mine->first()->id);
        $this->assertSame((int) $this->business->id, (int) $mine->first()->business_id);
        $this->assertCount(1, $this->contactsWithPhone('14155550104', (int) $foreignBusiness->id), 'the foreign Business is untouched');
        $this->assertSame($foreignContactId, (int) $this->contactsWithPhone('14155550104', (int) $foreignBusiness->id)->first()->id);
    }

    public function test_a_business_level_contact_without_a_location_does_not_capture_a_location_booking(): void
    {
        // Contract 15 §5.8: identity is the Location. A legacy Contact with NO Location
        // attribution is not "the person at Location A"; the booking attributes a fresh one.
        $legacy = $this->contactId();
        DB::table('contacts')->where('id', $legacy)->update(['phone' => '14155550105', 'location_id' => null]);

        $this->bookAt($this->typeA, '10:00', '+1 (415) 555-0105');

        $rows = $this->contactsWithPhone('14155550105', (int) $this->business->id);
        $this->assertCount(2, $rows);
        $this->assertSame(
            (int) $this->locationA->id,
            (int) $rows->firstWhere('id', '!=', $legacy)->location_id
        );
        $this->assertNull($rows->firstWhere('id', $legacy)->location_id, 'the legacy Contact is not rewritten');
    }

    public function test_the_owner_sees_the_public_booking_attributed_to_the_new_contact_in_the_schedule(): void
    {
        $this->bookAt($this->typeA, '10:00', '+1 (415) 555-0106', ['first_name' => 'Katherine', 'last_name' => 'Johnson']);
        $appointment = Appointment::query()->firstOrFail();

        $this->actAsOwner();
        $this->get($this->cal('schedule', $this->locationA, []) . '?view=day&date=2027-03-01')
            ->assertOk()
            ->assertSee('data-appointment="' . $appointment->uid . '"', false)
            ->assertSee('Katherine');
        $this->get($this->cal('appointments.show', $this->locationA, [$appointment->uid]))
            ->assertOk()
            ->assertSee('Katherine');
    }
}
