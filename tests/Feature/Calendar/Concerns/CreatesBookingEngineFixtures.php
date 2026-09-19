<?php

namespace Tests\Feature\Calendar\Concerns;

use App\Library\Calendar\AppointmentBookingService;
use App\Models\BookingType;
use App\Models\BookingTypeRoundRobinState;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\StaffAvailabilityRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures for Implementation Contract 15 Sub-slice C.
 *
 * Builds on 15B's authority fixtures, so every staff member here is a real,
 * currently-eligible member as LocationAccessGuard sees them — the engine
 * re-derives eligibility (§6) and would refuse a fake one.
 */
trait CreatesBookingEngineFixtures
{
    use CreatesCalendarAuthorityFixtures;

    /** A Monday, well clear of any DST edge, so the local-window maths is unambiguous. */
    protected function slotStart(string $time = '10:00:00'): Carbon
    {
        return Carbon::parse('2027-03-01 ' . $time, 'America/New_York')->utc();
    }

    protected function engine(): AppointmentBookingService
    {
        return app(AppointmentBookingService::class);
    }

    protected function bookingType(?BusinessLocation $location = null, array $overrides = []): BookingType
    {
        return BookingType::create(array_merge([
            'business_location_id' => ($location ?? $this->locationA)->id,
            'name' => 'Consultation',
            'duration_minutes' => 60,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * A full Monday window at the Location, in the Business's local timezone
     * (§5.2), wide enough that availability never accidentally explains a
     * refusal a test meant to attribute to a conflict.
     */
    protected function giveMondayAvailability(int $staffUserId, ?BusinessLocation $location = null): StaffAvailabilityRule
    {
        return StaffAvailabilityRule::create([
            'business_location_id' => ($location ?? $this->locationA)->id,
            'staff_user_id' => $staffUserId,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
        ]);
    }

    protected function contactId(): int
    {
        $group = ContactGroups::create([
            'customer_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'name' => 'Booking Group ' . uniqid(),
            'status' => true,
        ]);

        return DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'location_id' => $this->locationA->id,
            'group_id' => $group->id,
            'phone' => '1415555' . random_int(1000, 9999),
            'status' => Contacts::STATUS_SUBSCRIBE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A staff member who is eligible at the Location AND has a Monday window,
     * i.e. genuinely bookable.
     */
    protected function bookableStaff(?BusinessLocation $location = null): User
    {
        $user = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff);
        $this->giveMondayAvailability((int) $user->id, $location);

        return $user;
    }

    protected function cursorFor(BookingType $bookingType): ?int
    {
        $state = BookingTypeRoundRobinState::query()
            ->where('booking_type_id', $bookingType->id)
            ->first();

        return $state?->last_assigned_staff_user_id === null ? null : (int) $state->last_assigned_staff_user_id;
    }
}
