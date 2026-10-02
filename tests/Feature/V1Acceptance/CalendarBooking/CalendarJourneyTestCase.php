<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * V1 FINAL ACCEPTANCE 03 — shared journey harness.
 *
 * WHAT MAKES THIS A JOURNEY HARNESS AND NOT ANOTHER UNIT FIXTURE. Every
 * existing Calendar HTTP test pushes SeedCalendarEntitlementSnapshot
 * (`entitledCalendar()`), a test-only shim that answers the Calendar
 * entitlement question on the real stack's behalf. This harness deliberately
 * NEVER calls it: the Workspace holds a real Core plan assignment, the real
 * EntitlementManager decides, the real tenancy chain and the real
 * LocationAccessGuard run, and the customer shell renders its real menu.
 * If PlatformFeature::Calendar were not genuinely Available and packaged, every
 * journey below would 404 — which is exactly the regression an acceptance
 * lane exists to catch.
 *
 * Time is frozen on Thursday 2027-02-25 12:00 UTC so the Monday 2027-03-01
 * working window the shared staff fixtures open is unambiguously in the future
 * and clear of any DST edge (US spring-forward is 2027-03-14).
 */
abstract class CalendarJourneyTestCase extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;
    use CreatesExternalCalendarFixtures;

    /**
     * The app's exception handler renders every AuthorizationException as 401
     * (app/Exceptions/Handler.php), including the §6 availability-authority
     * refusal the controller docblock calls "403". The refusal and the absence
     * of a write are what matter; the status is the application's own.
     */
    protected const REFUSED_AUTHORITY = 401;

    /** @var array<int, object> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->bindFakeCalendarProviders();
        $this->recordLifecycleEvents();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The REAL dispatcher with a recording listener beside it — not
     * Event::fake — so after-commit behaviour and the real Automations
     * listener both run exactly as in production.
     */
    private function recordLifecycleEvents(): void
    {
        foreach ([
            AppointmentScheduled::class, AppointmentRescheduled::class, AppointmentCancelled::class,
            AppointmentCompleted::class, AppointmentNoShow::class,
        ] as $event) {
            Event::listen($event, function (object $e): void {
                $this->events[] = $e;
            });
        }
    }

    /** @return array<int, object> */
    protected function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, fn (object $e): bool => $e instanceof $class));
    }

    protected function forgetEvents(): void
    {
        $this->events = [];
    }

    // ---- actors & URLs -------------------------------------------------

    protected function actAs(User $user): static
    {
        $this->authenticate($user);

        return $this;
    }

    protected function actAsOwner(): static
    {
        return $this->actAs($this->owner->user);
    }

    protected function cal(string $name, BusinessLocation $location, array $extra = []): string
    {
        return $this->calendarUrl($name, array_merge($this->scopeFor($location), $extra));
    }

    protected function publicShow(BookingType $type, ?string $date = null): string
    {
        $url = route('public.booking.show', [$type->public_booking_uuid]);

        return $date === null ? $url : $url . '?date=' . $date;
    }

    protected function publicStore(BookingType $type): string
    {
        return route('public.booking.store', [$type->public_booking_uuid]);
    }

    /** @return array<int, string> the HH:MM labels the public page offers for $date */
    protected function offeredSlots(BookingType $type, string $date): array
    {
        $html = $this->get($this->publicShow($type, $date))->assertOk()->getContent();
        preg_match_all('/name="time" value="(\d{2}:\d{2})"/', $html, $matches);

        return $matches[1];
    }

    protected function guest(array $overrides = []): array
    {
        return array_merge([
            'date' => '2027-03-01', 'time' => '10:00',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+1 (415) 555-1234',
        ], $overrides);
    }

    // ---- data helpers --------------------------------------------------

    /** A type at $location with $staff nominated, the way an owner would. */
    protected function typeWithStaff(BusinessLocation $location, User ...$staff): BookingType
    {
        $type = $this->bookingType($location);
        $type->staff()->attach(array_map(fn (User $u): int => (int) $u->id, $staff));

        return $type;
    }

    /** A Contact bookable at $location (the staff form 404s a sibling Location's Contact). */
    protected function contactUidAt(BusinessLocation $location): string
    {
        $id = $this->contactId();
        DB::table('contacts')->where('id', $id)->update(['location_id' => $location->id]);

        return (string) DB::table('contacts')->where('id', $id)->value('uid');
    }

    protected function appointmentCount(?int $locationId = null): int
    {
        return DB::table('appointments')
            ->when($locationId !== null, fn ($q) => $q->where('business_location_id', $locationId))
            ->count();
    }

    protected function contactCount(?int $locationId = null): int
    {
        return DB::table('contacts')
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->count();
    }
}
