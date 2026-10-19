<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\Notifications\BookingNotificationReadiness;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Implementation Contract 15 §5.1, §6, §12.B — authenticated Booking Type
 * CRUD and the staff-pool assignment, at one Location.
 *
 * THREE GATES ON EVERY ACTION, in this order, none of them optional:
 *   1. Workspace/Business tenancy — the canonical
 *      BusinessRouteAccess::actorMayUseBusinessRoute() chain;
 *   2. the Calendar entitlement decision, via
 *      resolveEntitledBusinessTenancy(..., PlatformFeature::Calendar->value);
 *   3. LocationAccessGuard for the exact Location, via
 *      CalendarLocationResolver.
 *
 * Gate 2 is why every route here currently 404s even for an account owner
 * with a valid Location: `PlatformFeature::Calendar` is still `Planned`
 * (§11), so EntitlementManager refuses with `platform_feature_unavailable`
 * before any database read. That is the intended end state of this
 * sub-slice, not a defect — Sub-slice E's flip is what makes these surfaces
 * executable, and nothing here may flip it early.
 *
 * NO booking engine, no appointment lifecycle, no round-robin execution, no
 * calendar grid: Sub-slices C and D own those.
 */
class BookingTypesController extends Controller
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly BookingTypeManager $bookingTypes,
        private readonly CalendarLocationResolver $locations,
        private readonly BookingNotificationReadiness $notificationReadiness,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $bookingTypes = $this->bookingTypes->forLocation($location);

        return view('customer.business.calendar.booking-types.index', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'bookingTypes' => $bookingTypes,
            'readiness' => $bookingTypes->mapWithKeys(fn (BookingType $type): array => [
                $type->id => $this->bookingTypes->publicBookingReadiness($type),
            ]),
        ]);
    }

    public function create(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        return view('customer.business.calendar.booking-types.create', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        [, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $bookingType = $this->bookingTypes->create($location, $this->withinBusiness($this->validated($request), $business), (int) Auth::id());

        $editUrl = route('customer.workspaces.businesses.calendar.booking-types.edit', [
            $workspaceUid, $businessUid, $locationUid, $bookingType->uid,
        ]);

        // Home V2 action drawer: the same create, answered as JSON (validation already 422s).
        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Booking type created.', 'url' => $editUrl], 201);
        }

        return redirect($editUrl)->with('flash_success', 'Booking type created.');
    }

    public function edit(string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        return view('customer.business.calendar.booking-types.edit', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'bookingType' => $bookingType,
            'configuredStaff' => $this->bookingTypes->configuredStaffWithEligibility($bookingType),
            'readiness' => $this->bookingTypes->publicBookingReadiness($bookingType),
            'notificationReadiness' => $this->notificationReadiness->forBookingType($bookingType),
            'eligibleStaff' => $this->locations->eligibleStaff($workspace, $business, $location),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        $this->bookingTypes->update($bookingType, $this->withinBusiness($this->validated($request), $business));

        return $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid)
            ->with('flash_success', 'Booking type updated.');
    }

    public function toggleActive(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $this->bookingTypes->setActive($bookingType, (bool) $validated['is_active']);

        return $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid)
            ->with('flash_success', $validated['is_active'] ? 'Booking type activated.' : 'Booking type deactivated.');
    }

    /**
     * §5.1/§6 — configuration intent, never authorization. Every submitted
     * candidate is re-derived through LocationAccessGuard by the manager; an
     * ineligible id is refused and named back to the actor rather than
     * silently written or silently dropped.
     */
    public function syncStaff(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        // `nullable` on the members, not just `integer`: the form always
        // submits one empty hidden entry so that clearing every checkbox
        // still sends the key at all (otherwise an all-unchecked save would
        // look like "no change" rather than "remove everyone"). The manager
        // discards non-positive ids, so the placeholder never reaches a write.
        $validated = $request->validate([
            'staff_user_ids' => ['present', 'array'],
            'staff_user_ids.*' => ['nullable', 'integer'],
        ]);

        $result = $this->bookingTypes->syncStaff($bookingType, $validated['staff_user_ids']);

        $redirect = $this->backToEdit($workspaceUid, $businessUid, $locationUid, $bookingTypeUid);

        if ($result['refused'] !== []) {
            return $redirect->with(
                'flash_error',
                'Some staff members were not added: they are not currently authorized for this location.'
            );
        }

        return $redirect->with('flash_success', 'Booking type staff updated.');
    }

    /**
     * Gates 1 and 2 (tenancy + Calendar entitlement), then gate 3 (the exact
     * Location). A Location that belongs to another Business, or one this
     * actor cannot reach, is indistinguishable from one that does not exist.
     *
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    private function scope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->resolveEntitledBusinessTenancy(
            $workspaceUid,
            $businessUid,
            PlatformFeature::Calendar->value
        );

        $location = $this->locations->resolveForActor($business, $locationUid, (int) Auth::id());

        if ($location === null) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    private function bookingType(BusinessLocation $location, string $bookingTypeUid): BookingType
    {
        return $this->bookingTypes->findForLocation($location, $bookingTypeUid) ?? abort(404);
    }

    /**
     * A shared Zoom host may only be a connection of THIS Business. Anything else is dropped (never trusted from the form),
     * and Zoom cannot be chosen where this Business may not use it, so no plan check can be bypassed by posting a field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withinBusiness(array $data, Business $business): array
    {
        if (($data['meeting_location_type'] ?? null) === 'zoom') {
            abort_unless(app(\App\Library\Calendar\Zoom\ZoomEligibility::class)->allows($business), 403);
        }

        if (isset($data['zoom_host_connection_id'])) {
            $owned = \App\Models\ZoomConnection::query()->where('business_id', $business->id)->whereKey((int) $data['zoom_host_connection_id'])->exists();
            $data['zoom_host_connection_id'] = $owned ? (int) $data['zoom_host_connection_id'] : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'meeting_instructions' => ['nullable', 'string', 'max:2000'],
            // Meeting location: blank = the type's behaviour before this setting existed.
            'meeting_location_type' => ['nullable', 'string', Rule::in(\App\Library\Calendar\Zoom\ZoomHostResolver::TYPES)],
            'meeting_location_custom' => ['nullable', 'string', 'max:500', 'required_if:meeting_location_type,custom'],
            'zoom_host_connection_id' => ['nullable', 'integer'],
            'booking_window_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'minimum_notice_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'slot_interval_minutes' => ['sometimes', 'integer', 'in:'.implode(',', BookingType::SLOT_INTERVALS)],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_sms' => ['sometimes', 'boolean'],
            'reminders_submitted' => ['sometimes', 'boolean'],
            'reminder_offsets' => ['sometimes', 'array', 'max:'.BookingType::MAX_REMINDERS],
            'reminder_offsets.*' => ['integer', Rule::in(array_keys(BookingType::REMINDER_OFFSET_OPTIONS))],

            // Calendar Notifications V2: free timing, per-step channels and wording.
            'reminder_steps_submitted' => ['sometimes', 'boolean'],
            'reminder_steps' => ['sometimes', 'array', 'max:'.BookingType::MAX_REMINDERS],
            'reminder_steps.*.id' => ['nullable', 'string', 'max:64'],
            'reminder_steps.*.value' => ['required', 'integer', 'min:1', 'max:100000'],
            'reminder_steps.*.unit' => ['required', 'in:minutes,hours,days'],
            'reminder_steps.*.email' => ['nullable', 'boolean'],
            'reminder_steps.*.sms' => ['nullable', 'boolean'],
            'reminder_steps.*.enabled' => ['nullable', 'boolean'],
            'reminder_steps.*.email_subject' => ['nullable', 'string', 'max:200'],
            'reminder_steps.*.email_body' => ['nullable', 'string', 'max:3000'],
            'reminder_steps.*.sms_body' => ['nullable', 'string', 'max:480'],
            'confirmation_email_subject' => ['nullable', 'string', 'max:200'],
            'confirmation_email_body' => ['nullable', 'string', 'max:3000'],
            'confirmation_sms_body' => ['nullable', 'string', 'max:480'],
        ]);

        if ($request->boolean('reminder_steps_submitted')) {
            $data['reminder_steps'] = $this->reminderSteps($data['reminder_steps'] ?? []);
            unset($data['reminder_offsets']);
        } elseif ($request->boolean('reminders_submitted')) {
            // The older editor's list is authoritative again for a type that is saved through it.
            $data['reminder_steps'] = null;
        }
        unset($data['reminder_steps_submitted']);

        // A form that submitted the reminders section with every row removed sends
        // no `reminder_offsets` at all; that is "no reminders", not "unchanged".
        if ($request->boolean('reminders_submitted')) {
            $data['reminder_offsets'] = $data['reminder_offsets'] ?? [];
        }
        unset($data['reminders_submitted']);

        return $data;
    }

    /**
     * Value + unit rows -> stored steps. Timing is checked against the system
     * bounds and two steps may not share a moment (they would be the same send).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function reminderSteps(array $rows): array
    {
        $steps = [];
        $seen = [];
        $unit = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

        foreach (array_values($rows) as $i => $row) {
            $minutes = (int) $row['value'] * $unit[$row['unit']];

            if ($minutes < BookingType::MIN_REMINDER_MINUTES || $minutes > BookingType::MAX_REMINDER_MINUTES) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "reminder_steps.{$i}.value" => 'Choose a time between 5 minutes and 90 days before the appointment.',
                ]);
            }

            if (isset($seen[$minutes])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "reminder_steps.{$i}.value" => 'Two reminders cannot be sent at the same time.',
                ]);
            }

            $seen[$minutes] = true;
            $id = trim((string) ($row['id'] ?? ''));

            $steps[] = [
                'id' => $id === '' ? 'r' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)) : $id,
                'offset_minutes' => $minutes,
                'email' => (bool) ($row['email'] ?? false),
                'sms' => (bool) ($row['sms'] ?? false),
                'enabled' => ! array_key_exists('enabled', $row) || (bool) $row['enabled'],
                'email_subject' => trim((string) ($row['email_subject'] ?? '')) ?: null,
                'email_body' => trim((string) ($row['email_body'] ?? '')) ?: null,
                'sms_body' => trim((string) ($row['sms_body'] ?? '')) ?: null,
            ];
        }

        return $steps;
    }

    /** Renders one template with sample appointment details, exactly as a customer would receive it. */
    public function previewNotification(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): \Illuminate\Http\JsonResponse
    {
        [, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $bookingType = $this->bookingType($location, $bookingTypeUid);

        $data = $request->validate([
            'channel' => ['required', 'in:email,sms'],
            'kind' => ['required', 'in:confirmation,reminder'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:3000'],
        ]);

        $defaults = \App\Library\Calendar\Notifications\AppointmentNotificationContent::defaults();
        $key = $data['kind'] . '_' . $data['channel'];
        $details = new \App\Library\Calendar\Notifications\AppointmentNotificationDetails(
            appointmentUid: 'preview',
            businessName: (string) $business->name,
            bookingType: (string) $bookingType->name,
            date: now($business->timezone ?: 'UTC')->addDays(3)->format('l, F j, Y'),
            timeRange: '10:00 AM – 10:30 AM',
            startTime: '10:00 AM',
            zoneAbbr: 'UTC',
            timezone: (string) ($business->timezone ?: 'UTC'),
            where: \App\Library\Calendar\BookingLocationLabel::for($location),
            instructions: trim((string) $bookingType->meeting_instructions) ?: null,
            startUtc: now()->addDays(3),
            endUtc: now()->addDays(3)->addMinutes(30),
            sequence: 0,
        );

        $render = \App\Library\Calendar\Notifications\AppointmentNotificationContent::class;
        $body = trim((string) ($data['body'] ?? '')) !== '' ? $data['body'] : $defaults[$key . (($data['channel'] === 'email') ? '_body' : '_body')];
        $subject = trim((string) ($data['subject'] ?? '')) !== '' ? $data['subject'] : ($defaults[$data['kind'] . '_email_subject']);

        return response()->json([
            'subject' => $data['channel'] === 'email' ? $render::render($subject, $details, 'Alex') : null,
            'body' => $data['channel'] === 'sms'
                ? $render::renderSms($body, $details, 'Alex')
                : $render::render($body, $details, 'Alex'),
        ]);
    }

    private function backToEdit(string $workspaceUid, string $businessUid, string $locationUid, string $bookingTypeUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calendar.booking-types.edit', [
            $workspaceUid, $businessUid, $locationUid, $bookingTypeUid,
        ]);
    }
}
