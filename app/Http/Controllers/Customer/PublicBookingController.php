<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Calendar\BookingRefusedException;
use App\Http\Controllers\Controller;
use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\PublicSlotFinder;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Contract 15.E: a guest adapter into the canonical booking engine.
 *
 * One page (`show`), two small read-only JSON questions it asks while the
 * guest browses (`dates`, `slots`), and the one write (`store`). Every action
 * re-runs the identical six-check authority stack in resolve(); every refusal
 * is the same 404. Availability is never decided here: PublicSlotFinder asks
 * the canonical calculator and conflict detector, and AppointmentBookingService
 * re-verifies under lock when the guest confirms.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly CalendarLocationResolver $locations,
        private readonly CustomerAccountAccessGuard $accounts,
        private readonly EntitlementManager $entitlements,
        private readonly PublicSlotFinder $slots,
        private readonly AppointmentBookingService $booking,
        private readonly EloquentContactsRepository $contacts,
    ) {
    }

    public function show(Request $request, string $bookingTypeUuid): View
    {
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $timezone = $this->timezoneFor($business);
        $date = $request->query('date');
        if ($date !== null) {
            abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
            [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $date));
            abort_unless(checkdate($month, $dayOfMonth, $year), 404);
            $day = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
            [$first, $last] = $this->slots->window($timezone, $type);
            abort_unless($date >= $first && $date <= $last, 404);
        }

        // Without JavaScript the guest still gets a working page: the chosen
        // date's slots are rendered here, in the Business timezone.
        $staffIds = $this->eligibleStaffIds($type, $location);
        $slots = isset($day)
            ? $this->slots->slotsForDay($location, $type, $staffIds,
                $day->toDateString(), $timezone, $timezone)
            : [];

        return view('public.booking.show', [
            'type' => $type,
            'timezone' => $timezone,
            'day' => $day ?? null,
            'slots' => $slots,
            'brand' => $this->brand($type, $business, $location, $staffIds),
            'timezones' => $this->timezoneOptions(),
            'config' => [
                'datesUrl' => route('public.booking.dates', [$type->public_booking_uuid]),
                'slotsUrl' => route('public.booking.slots', [$type->public_booking_uuid]),
                'storeUrl' => route('public.booking.store', [$type->public_booking_uuid]),
                'businessTimezone' => $timezone,
                'durationMinutes' => (int) $type->duration_minutes,
                'typeName' => $type->name,
                'businessName' => $business->name,
            ],
        ]);
    }

    /** Dates in one month that have at least one offered slot, in the visitor's timezone. */
    public function dates(Request $request, string $bookingTypeUuid): JsonResponse
    {
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $businessTimezone = $this->timezoneFor($business);
        $visitorTimezone = $this->visitorTimezone($request, $businessTimezone);
        $month = $request->query('month');
        abort_unless(is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 404);

        [$first, $last] = $this->slots->window($businessTimezone, $type);
        $from = Carbon::now($visitorTimezone)->toDateString();
        $to = Carbon::createFromFormat('!Y-m-d', $last, $businessTimezone)->endOfDay()
            ->setTimezone($visitorTimezone)->toDateString();
        $monthStart = $month.'-01';
        $monthEnd = Carbon::createFromFormat('!Y-m-d', $monthStart, $visitorTimezone)->endOfMonth()->toDateString();
        $available = ($monthEnd < $from || $monthStart > $to)
            ? []
            : $this->slots->availableDates($location, $type,
                $this->eligibleStaffIds($type, $location), $month, $visitorTimezone, $businessTimezone);

        return $this->json([
            'month' => $month,
            'timezone' => $visitorTimezone,
            'today' => $from,
            'from' => $from,
            'to' => $to,
            'available' => $available,
        ]);
    }

    /** The offered times on one visitor-local day. */
    public function slots(Request $request, string $bookingTypeUuid): JsonResponse
    {
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $businessTimezone = $this->timezoneFor($business);
        $visitorTimezone = $this->visitorTimezone($request, $businessTimezone);
        $date = $request->query('date');
        abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
        [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $date));
        abort_unless(checkdate($month, $dayOfMonth, $year), 404);

        return $this->json([
            'date' => $date,
            'timezone' => $visitorTimezone,
            'slots' => $this->slots->slotsForDay($location, $type,
                $this->eligibleStaffIds($type, $location), $date, $visitorTimezone, $businessTimezone),
        ]);
    }

    public function store(Request $request, string $bookingTypeUuid): RedirectResponse|JsonResponse
    {
        // Re-run the identical authority stack before inspecting any guest input.
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);
        $wantsJson = $request->ajax();
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $validator->after(function ($validator) use ($request, $type): void {
            $phone = trim(str_replace(['+', '-', '(', ')', ' '], '', (string) $request->input('phone')));
            if (! $validator->errors()->has('phone') && ($phone === '' || strlen($phone) > 32 || ! ctype_digit($phone))) {
                $validator->errors()->add('phone', 'Enter a valid phone number.');
            }
            if (! $validator->errors()->has('time') && ! $this->slots->onGrid((string) $request->input('time'), $type)) {
                $validator->errors()->add('time', 'Choose an available time.');
            }
        });
        if ($validator->fails()) {
            return $wantsJson
                ? $this->json(['message' => 'Please check your details.', 'errors' => $validator->errors()->toArray()], 422)
                : throw new ValidationException($validator);
        }
        $data = $validator->validated();
        $timezone = $this->timezoneFor($business);
        $start = $this->slots->instantFor($data['date'], $data['time'], $timezone);
        if ($start === null || ! $this->slots->mayBook($type, $timezone, $data['date'], $start)) {
            return $this->refused($wantsJson, 'Choose an available time.');
        }

        try {
            $appointment = $this->booking->bookWithRoundRobinContactResolver(
                $type,
                $start,
                function () use ($location, $business, $data): int {
                    // The engine holds tier 1 and tier 2 before calling us.
                    $group = ContactGroups::query()->where('business_id', $business->id)->orderBy('id')->first();
                    if ($group === null) {
                        $group = $this->contacts->store([
                            'name' => 'Contacts', 'business_id' => $business->id, 'user_id' => $business->customer_id,
                        ]);
                    }
                    $this->ensureEmailField($group);
                    $contact = $this->contacts->findOrCreateForBooking(
                        $location, $group, $data['phone'], [
                            'FIRST_NAME' => $data['first_name'],
                            'LAST_NAME' => $data['last_name'],
                            'EMAIL' => $data['email'],
                        ]
                    );
                    return (int) $contact->id;
                }
            );
        } catch (BookingRefusedException $exception) {
            return $this->refused($wantsJson, 'That time is no longer available. Choose another time.', 409);
        } catch (ValidationException $exception) {
            // A blacklisted number surfaces from inside the booking transaction.
            if ($wantsJson) {
                return $this->json(['message' => 'Please check your details.', 'errors' => $exception->errors()], 422);
            }
            throw $exception;
        }

        $visitorTimezone = $this->visitorTimezone($request, $timezone, 'visitor_timezone');
        $summary = $this->summary($type, $business, $location, $start, $visitorTimezone);
        if ($wantsJson) {
            return $this->json(['status' => 'confirmed', 'booking' => $summary], 201);
        }
        $request->session()->flash('booking_confirmation', $summary);

        return redirect()->route('public.booking.confirmed', [$type->public_booking_uuid]);
    }

    public function confirmed(string $bookingTypeUuid): View
    {
        [$type, $location, $business] = $this->resolve($bookingTypeUuid);

        return view('public.booking.confirmed', [
            'type' => $type,
            'brand' => $this->brand($type, $business, $location, []),
            'summary' => session('booking_confirmation'),
        ]);
    }

    /**
     * A Contact's email is a custom field, and a group's default fields are
     * only phone and names, so without this the email the guest typed would be
     * silently dropped. The group row is locked first (we are inside the
     * booking transaction) so two simultaneous bookings cannot both add it.
     */
    private function ensureEmailField(ContactGroups $group): void
    {
        $locked = ContactGroups::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        if ($locked->getFieldByTag('EMAIL') === null) {
            $locked->contactGroupFields()->create([
                'contact_group_id' => $locked->id,
                'type' => 'text',
                'label' => __('locale.labels.email'),
                'tag' => 'EMAIL',
                'required' => false,
                'visible' => true,
            ]);
        }
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
        // Settings that cannot produce slots fail closed like every other refusal.
        abort_if($type->schedulingProblem() !== null, 404);
        $this->timezoneFor($business);

        return [$type, $location, $business];
    }

    /** A Business whose stored timezone is not a real zone cannot be scheduled: refuse like every other authority failure. */
    private function timezoneFor(Business $business): string
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            abort(404);
        }

        return $timezone;
    }

    /**
     * The visitor's display zone: a real IANA identifier or the Business zone.
     * It only decides which day a slot is shown on and how it is labelled; the
     * booked instant is always the Business-local date + time the slot carries.
     */
    private function visitorTimezone(Request $request, string $fallback, string $key = 'tz'): string
    {
        $candidate = $request->input($key);

        return is_string($candidate) && in_array($candidate, \DateTimeZone::listIdentifiers(), true)
            ? $candidate
            : $fallback;
    }

    /** @return array<string, list<string>> region => identifiers, for the timezone picker */
    private function timezoneOptions(): array
    {
        $groups = [];
        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            $groups[str_contains($identifier, '/') ? strtok($identifier, '/') : 'Other'][] = $identifier;
        }

        return $groups;
    }

    /**
     * Everything the page may say about who is being booked. The Business OS
     * has no Business logo, so the identity is the Business name; the accent is
     * the Booking Type's own colour when it is a valid hex.
     *
     * @param  list<int>  $staffIds
     */
    private function brand(BookingType $type, Business $business, BusinessLocation $location, array $staffIds): array
    {
        $color = is_string($type->color) && preg_match('/^#[0-9a-fA-F]{6}$/', $type->color) ? $type->color : null;
        $staff = count($staffIds) === 1 ? User::query()->find($staffIds[0]) : null;

        return [
            'business' => $business->name,
            'initial' => mb_strtoupper(mb_substr(trim((string) $business->name), 0, 1)) ?: 'B',
            'accent' => $color,
            'staff' => $staff ? trim($staff->first_name.' '.$staff->last_name) : null,
            'where' => $this->whereLine($location),
            'instructions' => $type->meeting_instructions,
        ];
    }

    private function whereLine(BusinessLocation $location): ?string
    {
        if ($location->service_mode === \App\Enums\Business\BusinessServiceMode::Online) {
            return 'Online';
        }
        $parts = $location->public_address
            ? array_filter([$location->address_line_1, $location->city, $location->region])
            : array_filter([$location->name]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function summary(BookingType $type, Business $business, BusinessLocation $location, Carbon $start, string $timezone): array
    {
        $local = $start->copy()->setTimezone($timezone);
        $end = $start->copy()->addMinutes((int) $type->duration_minutes);

        return [
            'type' => $type->name,
            'business' => $business->name,
            'where' => $this->whereLine($location),
            'instructions' => $type->meeting_instructions,
            'date' => $local->format('l, F j, Y'),
            'time' => $local->format('g:i A').' – '.$end->copy()->setTimezone($timezone)->format('g:i A'),
            'timezone' => $timezone,
            'duration' => (int) $type->duration_minutes,
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
        ];
    }

    private function refused(bool $wantsJson, string $message, int $status = 422): RedirectResponse|JsonResponse
    {
        return $wantsJson
            ? $this->json(['message' => $message, 'errors' => ['time' => [$message]]], $status)
            : back()->withInput()->withErrors(['time' => $message]);
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }

    private function eligibleStaffIds(BookingType $type, BusinessLocation $location): array
    {
        return $type->staff()->orderBy('users.id')->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->locations->isEligible($id, $location))
            ->values()->all();
    }
}
