<?php

namespace Tests\Feature\Calendar;

use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\StaffAvailabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * V1 acceptance for the unauthenticated scheduler: the exact instant a guest
 * sees is the exact instant that is booked — across DST edges, malformed
 * input, stale pages and concurrent takers — and every refusal leaves nothing
 * behind. Complements PublicBookingTest (authority stack, Contact identity).
 */
class PublicSchedulerAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    private BookingType $type;

    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $staff = $this->bookableStaff();
        $this->staffId = (int) $staff->id;
        $this->type = $this->bookingType();
        $this->type->staff()->attach($staff->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(?BookingType $type = null): string
    {
        return route('public.booking.show', [($type ?? $this->type)->public_booking_uuid]);
    }

    private function storeUrl(?BookingType $type = null): string
    {
        return route('public.booking.store', [($type ?? $this->type)->public_booking_uuid]);
    }

    private function payload(string $date = '2027-03-01', string $time = '10:00', array $overrides = []): array
    {
        return array_merge([
            'date' => $date, 'time' => $time,
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+1 (415) 555-1234',
        ], $overrides);
    }

    /** @return array<int, string> */
    private function offeredSlots(string $date): array
    {
        $html = $this->get($this->url().'?date='.$date)->assertOk()->getContent();
        preg_match_all('/name="time" value="(\d{2}:\d{2})"/', $html, $matches);

        return $matches[1];
    }

    /** Sundays are the DST days in the US; a round-the-clock window keeps availability out of the picture. */
    private function openSundayAllDay(): void
    {
        StaffAvailabilityRule::create([
            'business_location_id' => $this->locationA->id,
            'staff_user_id' => $this->staffId,
            'day_of_week' => 0,
            'start_time' => '00:00:00',
            'end_time' => '24:00:00',
        ]);
    }

    public function test_end_to_end_booking_page_to_confirmed_appointment_at_the_location(): void
    {
        $this->assertContains('10:00', $this->offeredSlots('2027-03-01'));

        $this->post($this->storeUrl(), $this->payload())
            ->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));
        $this->get(route('public.booking.confirmed', [$this->type->public_booking_uuid]))
            ->assertOk()->assertSee('Booking confirmed');

        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame((int) $this->locationA->id, (int) $appointment->contact->location_id);
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());

        $this->assertNotContains('10:00', $this->offeredSlots('2027-03-01'), 'The booked slot must leave the page.');
    }

    public function test_a_slot_taken_between_page_render_and_submit_is_refused_and_leaves_one_appointment(): void
    {
        $this->assertContains('10:00', $this->offeredSlots('2027-03-01'));

        // Someone else wins the slot after this guest's page rendered.
        $this->engine()->book($this->type, $this->staffId, $this->contactId(), $this->slotStart('10:00:00'));

        $this->post($this->storeUrl(), $this->payload())->assertSessionHasErrors('time');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('contacts', 1); // only the other taker's: a refusal leaves no Contact behind
    }

    public function test_the_spring_forward_gap_is_never_offered_or_accepted(): void
    {
        Carbon::setTestNow('2027-03-10 12:00:00 UTC');
        $this->openSundayAllDay();

        $slots = $this->offeredSlots('2027-03-14');

        $this->assertContains('01:30', $slots);
        $this->assertContains('03:00', $slots);
        $this->assertNotContains('02:00', $slots, 'The skipped local hour does not exist.');
        $this->assertNotContains('02:30', $slots);
        $this->assertSame($slots, array_values(array_unique($slots)), 'No label may be offered twice.');

        // 02:30 would silently normalise to 03:30 — a time the guest never saw.
        $this->post($this->storeUrl(), $this->payload('2027-03-14', '02:30'))->assertSessionHasErrors('time');
        $this->assertDatabaseCount('appointments', 0);

        // 03:00 EDT is 07:00 UTC; the guest booked what they saw.
        $this->post($this->storeUrl(), $this->payload('2027-03-14', '03:00'))->assertRedirect();
        $this->assertSame(
            '2027-03-14 07:00:00',
            Carbon::parse(Appointment::query()->sole()->start_at)->utc()->toDateTimeString()
        );
    }

    public function test_the_fall_back_repeated_hour_is_never_offered_twice_or_guessed(): void
    {
        Carbon::setTestNow('2027-11-03 12:00:00 UTC');
        $this->openSundayAllDay();

        $slots = $this->offeredSlots('2027-11-07');

        $this->assertContains('00:30', $slots);
        $this->assertContains('02:00', $slots);
        $this->assertNotContains('01:00', $slots, 'A repeated wall-clock label names two instants.');
        $this->assertNotContains('01:30', $slots);
        $this->assertSame($slots, array_values(array_unique($slots)), 'No label may be offered twice.');

        $this->post($this->storeUrl(), $this->payload('2027-11-07', '01:30'))->assertSessionHasErrors('time');
        $this->assertDatabaseCount('appointments', 0);

        // 02:00 EST (after the shift) is 07:00 UTC.
        $this->post($this->storeUrl(), $this->payload('2027-11-07', '02:00'))->assertRedirect();
        $this->assertSame(
            '2027-11-07 07:00:00',
            Carbon::parse(Appointment::query()->sole()->start_at)->utc()->toDateTimeString()
        );
    }

    public function test_a_guest_supplied_timezone_never_moves_the_slot(): void
    {
        $this->assertSame(
            $this->offeredSlots('2027-03-01'),
            $this->offeredSlots('2027-03-01&timezone=Asia/Tokyo&tz=Pacific/Kiritimati')
        );

        $this->post($this->storeUrl(), $this->payload(overrides: ['timezone' => 'Asia/Tokyo', 'tz' => 'Pacific/Kiritimati']))
            ->assertRedirect();

        $this->assertSame(
            '2027-03-01 15:00:00',
            Carbon::parse(Appointment::query()->sole()->start_at)->utc()->toDateTimeString()
        );
    }

    public function test_a_business_with_an_invalid_stored_timezone_refuses_like_any_other_authority_failure(): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['timezone' => 'Mars/Olympus_Mons']);

        $this->get($this->url().'?date=2027-03-01')->assertNotFound();
        $this->post($this->storeUrl(), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
    }

    #[DataProvider('malformedInputs')]
    public function test_malformed_date_or_time_input_is_rejected_without_side_effects(array $overrides): void
    {
        $this->post($this->storeUrl(), $this->payload(overrides: $overrides))->assertSessionHasErrors();

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public static function malformedInputs(): array
    {
        return [
            'impossible calendar date' => [['date' => '2027-02-30']],
            'wrong date format' => [['date' => '03/01/2027']],
            'iso timestamp as date' => [['date' => '2027-03-01T10:00:00Z']],
            'hour out of range' => [['time' => '25:00']],
            'off-grid minute' => [['time' => '10:15']],
            'seconds supplied' => [['time' => '10:00:00']],
            'array time' => [['time' => ['10:00']]],
            'missing time' => [['time' => '']],
            'non-numeric phone' => [['phone' => 'call me maybe']],
        ];
    }

    public function test_a_malformed_query_date_is_a_404_not_a_server_error(): void
    {
        foreach (['2027-13-01', 'tomorrow', '2027-3-1', '2027-03-01 10:00', '../etc/passwd'] as $date) {
            $this->get($this->url().'?date='.urlencode($date))->assertNotFound();
        }
        $this->get($this->url().'?date[]=2027-03-01')->assertNotFound();
    }

    public function test_expired_and_out_of_horizon_slots_are_rejected(): void
    {
        Carbon::setTestNow('2027-03-01 15:30:00 UTC'); // 10:30 local: 10:00 has begun.
        $this->post($this->storeUrl(), $this->payload('2027-03-01', '10:00'))->assertSessionHasErrors('time');
        $this->post($this->storeUrl(), $this->payload('2027-02-22', '10:00'))->assertSessionHasErrors('time');
        $this->post($this->storeUrl(), $this->payload('2027-04-05', '10:00'))->assertSessionHasErrors('time');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_disabled_or_foreign_booking_type_is_not_bookable_and_never_leaks(): void
    {
        $this->type->forceFill(['is_active' => false])->save();
        $this->get($this->url().'?date=2027-03-01')->assertNotFound();
        $this->post($this->storeUrl(), $this->payload())->assertNotFound();

        $unknown = '7f1c2b9e-0000-4000-8000-000000000000';
        $this->get('/book/'.$unknown)->assertNotFound();
        $this->post('/book/'.$unknown, $this->payload())->assertNotFound();

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_cross_location_manipulation_cannot_redirect_the_booking(): void
    {
        $typeAtB = $this->bookingType($this->locationB);
        $staffAtB = $this->bookableStaff($this->locationB);
        $typeAtB->staff()->attach($staffAtB->id);

        $this->post($this->storeUrl(), $this->payload(overrides: [
            'business_location_id' => $this->locationB->id,
            'location_uid' => $this->locationB->uid,
            'booking_type_id' => $typeAtB->id,
            'staff_user_id' => $staffAtB->id,
            'staff' => $staffAtB->id,
        ]))->assertRedirect();

        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame((int) $this->type->id, (int) $appointment->booking_type_id);
        $this->assertSame($this->staffId, (int) $appointment->staff_user_id);
    }
}
