<?php

namespace App\Library\AgencySite;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\StaffAvailabilityService;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BookingType;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * White Label — the Agency site's link to the Agency's OWN native calendar.
 *
 * No scheduling logic lives here. A "booking CTA" on the site is the existing public booking page of one of the
 * Agency Business's real booking types (route public.booking.show), so slots, timezones, questions,
 * confirmations, cancellations, rescheduling and the Appointment itself are all produced by the calendar module.
 * This class only lists the Agency's types, creates one through BookingTypeManager, and answers "is the selected
 * type something a visitor can actually book right now?".
 */
final class AgencySiteBookingService
{
    public function __construct(
        private readonly BookingTypeManager $types,
        private readonly StaffAvailabilityService $availability,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    public function business(Workspace $agency): ?Business
    {
        return Business::query()->where('workspace_id', $agency->id)->orderBy('id')->first();
    }

    public function calendarEntitled(Workspace $agency): bool
    {
        return $this->entitlements->decideForWorkspace($agency, PlatformFeature::Calendar->value)->allowed;
    }

    /** @return list<array{uuid: string, name: string, duration: int, location: string, active: bool, ready: bool, reason: ?string}> */
    public function options(Workspace $agency): array
    {
        $business = $this->business($agency);

        if ($business === null) {
            return [];
        }

        $locationIds = BusinessLocation::query()->where('business_id', $business->id)->pluck('id');

        return BookingType::query()
            ->whereIn('business_location_id', $locationIds)
            ->with('location')
            ->orderBy('name')
            ->get()
            ->map(function (BookingType $type): array {
                $readiness = $this->types->publicBookingReadiness($type);

                return [
                    'uuid' => (string) $type->public_booking_uuid,
                    'name' => (string) $type->name,
                    'duration' => (int) $type->duration_minutes,
                    'location' => (string) ($type->location?->name ?? ''),
                    'active' => $type->isActive(),
                    'ready' => $readiness['ready'],
                    'reason' => $readiness['reason'],
                ];
            })
            ->all();
    }

    /** The booking type with this public uuid IF it belongs to this Agency's own Business — else null. */
    public function find(Workspace $agency, ?string $uuid): ?BookingType
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $business = $this->business($agency);

        if ($business === null) {
            return null;
        }

        return BookingType::query()
            ->where('public_booking_uuid', $uuid)
            ->whereIn('business_location_id', BusinessLocation::query()->where('business_id', $business->id)->select('id'))
            ->first();
    }

    /** @return array{ready: bool, reason: ?string} */
    public function readiness(Workspace $agency, ?string $uuid): array
    {
        if ($uuid === null || $uuid === '') {
            return ['ready' => false, 'reason' => 'No booking type is selected.'];
        }

        $type = $this->find($agency, $uuid);

        if ($type === null) {
            return ['ready' => false, 'reason' => 'The selected booking type no longer exists. Choose another.'];
        }

        if (! $this->calendarEntitled($agency)) {
            return ['ready' => false, 'reason' => 'Calendar is not included in your plan.'];
        }

        return $this->types->publicBookingReadiness($type);
    }

    /** The public booking address, or null unless the type is genuinely bookable right now. */
    public function publicUrl(Workspace $agency, ?string $uuid): ?string
    {
        return $this->readiness($agency, $uuid)['ready'] ? route('public.booking.show', (string) $uuid) : null;
    }

    /**
     * Create a booking type for the Agency's Business without leaving the editor, make the owner bookable on it,
     * and (optionally) give the owner standard weekday hours so the page can offer times straight away.
     *
     * @param  array{name: string, duration_minutes: int, meeting_location_type?: ?string, meeting_location_custom?: ?string, meeting_instructions?: ?string, description?: ?string, default_hours?: bool}  $attributes
     *
     * @throws ValidationException
     */
    public function create(int $actorUserId, Workspace $agency, array $attributes): BookingType
    {
        $business = $this->business($agency);

        if ($business === null || (int) $agency->owner_user_id !== $actorUserId) {
            throw ValidationException::withMessages(['name' => 'Only the agency owner can create a booking type.']);
        }

        if (! $this->calendarEntitled($agency)) {
            throw ValidationException::withMessages(['name' => 'Calendar is not included in your plan.']);
        }

        $location = BusinessLocation::query()->where('business_id', $business->id)->orderByDesc('is_primary')->orderBy('id')->first();

        if ($location === null) {
            throw ValidationException::withMessages(['name' => 'Add a business location in your calendar settings first.']);
        }

        $name = trim((string) ($attributes['name'] ?? ''));
        $duration = (int) ($attributes['duration_minutes'] ?? 0);

        if ($name === '' || mb_strlen($name) > 120 || $name !== strip_tags($name)) {
            throw ValidationException::withMessages(['name' => 'Enter a name up to 120 characters, without HTML.']);
        }

        if ($duration < 5 || $duration > 480) {
            throw ValidationException::withMessages(['duration_minutes' => 'Choose a duration between 5 and 480 minutes.']);
        }

        $type = $this->types->create($location, [
            'name' => $name,
            'description' => $attributes['description'] ?? null,
            'duration_minutes' => $duration,
            'meeting_location_type' => $attributes['meeting_location_type'] ?? null,
            'meeting_location_custom' => $attributes['meeting_location_custom'] ?? null,
            'meeting_instructions' => $attributes['meeting_instructions'] ?? null,
            'is_active' => true,
        ], $actorUserId);

        $this->types->syncStaff($type, [$actorUserId]);

        if (! empty($attributes['default_hours'])) {
            $week = [];

            foreach ([1, 2, 3, 4, 5] as $day) {
                $week[$day] = [['start' => '09:00', 'end' => '17:00']];
            }

            $this->availability->replaceWeek($agency, $business, $location, $actorUserId, $week, $actorUserId);
        }

        return $type->refresh();
    }

    /** Where the owner edits everything else about this booking type (availability, questions, reminders). */
    public function editUrl(Workspace $agency, BookingType $type): ?string
    {
        $business = $this->business($agency);
        $location = $type->location;

        if ($business === null || $location === null) {
            return null;
        }

        return route('customer.workspaces.businesses.calendar.booking-types.edit', [$agency->uid, $business->uid, $location->uid, $type->uid]);
    }
}
