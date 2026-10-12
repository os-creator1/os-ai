<?php

namespace App\Library\Dashboard\Home;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarScheduleService;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Home V2 — the Business Home's ONE reader of Calendar facts.
 *
 * It adds no booking rule and no availability rule: Locations come from
 * CalendarScheduleService::accessibleLocations() (LocationAccessGuard, per
 * Location, for this actor), booking-type readiness is
 * BookingTypeManager::publicBookingReadiness() unchanged, and an appointment is
 * whatever the `appointments` table says it is. What it adds is BOUNDS, because
 * Home is the most visited page:
 *
 *  - at most MAX_TYPES booking types are read, and readiness (which costs a few
 *    statements per type) is evaluated for at most MAX_EVALUATED of them;
 *  - appointments are only ever counted over Locations the actor may reach, in
 *    one statement for however many windows are asked for.
 *
 * A restricted staff member therefore never learns of, or counts toward, a
 * Location they cannot reach.
 */
final class HomeBookingReader
{
    public const MAX_TYPES = 10;

    public const MAX_EVALUATED = 3;

    /** @var array<string, array<string, mixed>> memoised per Business+actor within one request */
    private array $memo = [];

    public function __construct(
        private readonly CalendarScheduleService $schedule,
        private readonly BookingTypeManager $bookingTypes,
    ) {
    }

    /**
     * @return array{
     *   locations: Collection<int, BusinessLocation>,
     *   locationIds: array<int, int>,
     *   typeCount: int,
     *   activeTypeCount: int,
     *   readyCount: int,
     *   evaluated: int,
     *   problems: array<int, array{name: string, reason: string, locationUid: string, typeUid: string}>,
     *   firstLocationUid: ?string
     * }
     */
    public function summary(Business $business, int $actorUserId): array
    {
        $key = $business->id . ':' . $actorUserId;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $locations = $this->schedule->accessibleLocations($business, $actorUserId);
        $locationIds = $locations->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $locationUids = $locations->mapWithKeys(fn (BusinessLocation $l): array => [(int) $l->id => (string) $l->uid])->all();

        $types = $locationIds === []
            ? collect()
            : BookingType::query()
                ->whereIn('business_location_id', $locationIds)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->limit(self::MAX_TYPES)
                ->get();

        $active = $types->filter(fn (BookingType $t): bool => $t->isActive())->values();
        $ready = 0;
        $problems = [];

        foreach ($active->take(self::MAX_EVALUATED) as $type) {
            // The Location is already in hand: hand it over rather than let readiness re-read it.
            $type->setRelation('location', $locations->firstWhere('id', $type->business_location_id));
            $readiness = $this->bookingTypes->publicBookingReadiness($type);

            if ($readiness['ready']) {
                $ready++;

                continue;
            }

            $problems[] = [
                'name' => (string) $type->name,
                'reason' => (string) ($readiness['reason'] ?? 'This booking type cannot take bookings yet.'),
                'locationUid' => $locationUids[(int) $type->business_location_id] ?? '',
                'typeUid' => (string) $type->uid,
            ];
        }

        return $this->memo[$key] = [
            'locations' => $locations,
            'locationIds' => $locationIds,
            'typeCount' => $types->count(),
            'activeTypeCount' => $active->count(),
            'readyCount' => $ready,
            'evaluated' => min(self::MAX_EVALUATED, $active->count()),
            'problems' => $problems,
            'firstLocationUid' => $locations->first()?->uid,
        ];
    }

    /**
     * Appointments BOOKED (a row was created) in each half-open window, over
     * the given Locations, excluding ones since cancelled. One statement.
     *
     * "Booked" is a statement about the booking event, never about money or
     * attendance: a completed or no-show appointment was still booked, and
     * nothing here says it was paid for.
     *
     * @param  array<int, int>  $locationIds
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows  label => [start, end)
     * @return array<string, int>
     */
    public function bookedCounts(array $locationIds, array $windows): array
    {
        $result = array_fill_keys(array_keys($windows), 0);

        if ($locationIds === [] || $windows === []) {
            return $result;
        }

        $selects = [];
        $bindings = [];

        foreach (array_values($windows) as $i => [$start, $end]) {
            $selects[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS w{$i}";
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $end->toDateTimeString();
        }

        // Bounds keep the windows' own (storage-timezone) wall-clock strings.
        $earliest = null;
        $latest = null;

        foreach ($windows as [$start, $end]) {
            $earliest = $earliest === null || $start->getTimestamp() < $earliest->getTimestamp() ? $start : $earliest;
            $latest = $latest === null || $end->getTimestamp() > $latest->getTimestamp() ? $end : $latest;
        }

        $row = Appointment::query()
            ->whereIn('business_location_id', $locationIds)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('created_at', '>=', $earliest->toDateTimeString())
            ->where('created_at', '<', $latest->toDateTimeString())
            ->selectRaw(implode(', ', $selects), $bindings)
            ->toBase()
            ->first();

        foreach (array_keys($windows) as $i => $label) {
            $result[$label] = (int) ($row->{"w{$i}"} ?? 0);
        }

        return $result;
    }
}
