<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Calendar\BookingRefusedException;
use App\Http\Controllers\Controller;
use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\BookingConflictDetector;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\StaffAvailabilityCalculator;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Workspace;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/** Contract 15.E: a guest adapter into the canonical booking engine. */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly CalendarLocationResolver $locations,
        private readonly CustomerAccountAccessGuard $accounts,
        private readonly EntitlementManager $entitlements,
        private readonly StaffAvailabilityCalculator $availability,
        private readonly BookingConflictDetector $conflicts,
        private readonly AppointmentBookingService $booking,
        private readonly EloquentContactsRepository $contacts,
    ) {
    }

    public function show(Request $request, string $bookingTypeUuid): View
    {
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $date = $request->query('date');
        if ($date !== null) {
            abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
            [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $date));
            abort_unless(checkdate($month, $dayOfMonth, $year), 404);
        }
        $day = $date === null
            ? Carbon::now($timezone)->addDay()->startOfDay()
            : Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        abort_unless($day && $day->startOfDay()->betweenIncluded(
            Carbon::now($timezone)->startOfDay(), Carbon::now($timezone)->addDays(30)->endOfDay()
        ), 404);

        $slots = [];
        $staffIds = $this->eligibleStaffIds($type, $location);
        for ($local = $day->copy()->startOfDay(); $local->isSameDay($day); $local->addMinutes(30)) {
            $start = $local->copy()->utc();
            $end = $start->copy()->addMinutes($type->duration_minutes);
            if ($start->lessThanOrEqualTo(now()->utc())) {
                continue;
            }
            foreach ($staffIds as $staffId) {
                if ($this->availability->isAvailable($staffId, $location, $start, $end)
                    && ! $this->conflicts->hasConflict($staffId, $start, $end)) {
                    $slots[] = $local->format('H:i');
                    break;
                }
            }
        }

        return view('public.booking.show', compact('type', 'timezone', 'day', 'slots'));
    }

    public function store(Request $request, string $bookingTypeUuid): RedirectResponse
    {
        // Re-run the identical authority stack before inspecting any guest input.
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $phone = trim(str_replace(['+', '-', '(', ')', ' '], '', $data['phone']));
        if ($phone === '' || strlen($phone) > 32 || ! ctype_digit($phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
        }
        if (! in_array(substr($data['time'], 3), ['00', '30'], true)) {
            throw ValidationException::withMessages(['time' => 'Choose an available time.']);
        }
        $start = Carbon::createFromFormat('!Y-m-d H:i', $data['date'].' '.$data['time'], $timezone)->utc();
        if ($start->lessThanOrEqualTo(now()->utc()) || $start->greaterThan(now()->addDays(31)->utc())) {
            return back()->withInput()->withErrors(['time' => 'Choose an available time.']);
        }

        $phone = $this->contacts->ensureBookingIdentityLock($location, $phone);

        try {
            // The outer transaction includes Contact and Appointment. READ COMMITTED
            // lets the engine's nested locked reads see a prior writer's commit.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::transaction(function () use ($type, $location, $business, $data, $start, $phone): void {
                $this->contacts->lockBookingIdentity($location, $phone);
                $group = ContactGroups::query()->where('business_id', $business->id)->orderBy('id')->first();
                if ($group === null) {
                    $group = $this->contacts->store([
                        'name' => 'Contacts', 'business_id' => $business->id, 'user_id' => $business->customer_id,
                    ]);
                }
                $contact = $this->contacts->findOrCreateForBooking(
                    $location, $group, $data['phone'], [
                        'FIRST_NAME' => $data['first_name'],
                        'LAST_NAME' => $data['last_name'],
                    ]
                );
                $this->booking->bookWithRoundRobin($type, (int) $contact->id, $start);
            }, 3);
        } catch (BookingRefusedException $exception) {
            return back()->withInput()->withErrors(['time' => 'That time is no longer available. Choose another time.']);
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return redirect()->route('public.booking.confirmed', [$type->public_booking_uuid]);
    }

    public function confirmed(string $bookingTypeUuid): View
    {
        $this->resolve($bookingTypeUuid);
        return view('public.booking.confirmed');
    }

    /** Six public checks in Contract 15 §6 order; all refusals have one response. */
    private function resolve(string $uuid): array
    {
        $type = BookingType::query()->where('public_booking_uuid', $uuid)->first() ?? abort(404);
        $location = BusinessLocation::query()->find($type->business_location_id) ?? abort(404);
        abort_unless($location->isActive(), 404);

        $business = Business::query()->find($location->business_id) ?? abort(404);
        $workspace = Workspace::query()->find($business->workspace_id) ?? abort(404);
        abort_unless($business->status === BusinessStatus::Active && $workspace->is_active, 404);
        abort_if($this->accounts->decisionForBusiness($business)->isLocked(), 404);
        abort_unless($this->entitlements->decide(
            $workspace, $business, PlatformFeature::Calendar->value, (int) $business->customer_id
        )->allowed, 404);
        abort_unless($type->isActive() && (int) $type->business_location_id === (int) $location->id, 404);
        abort_if($this->eligibleStaffIds($type, $location) === [], 404);

        return [$type, $location, $business];
    }

    private function eligibleStaffIds(BookingType $type, BusinessLocation $location): array
    {
        return $type->staff()->orderBy('users.id')->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->locations->isEligible($id, $location))
            ->values()->all();
    }
}
