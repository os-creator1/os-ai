<?php

namespace App\Library\Website\Setup;

use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\StaffAvailabilityService;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The website setup's way of letting the OWNER configure the booking type their niche blueprint installed (inactive, with no
 * one assigned, by design) — never a booking type the wizard invents.
 *
 * It finds the booking type through the business's own blueprint installation record (a `booking_type` component installed
 * for THIS business), applies only what the owner answered (length, notice, days and a daily window for themselves) and turns
 * it on ONLY when the Calendar would genuinely accept a booking (BookingTypeManager::publicBookingReadiness). Otherwise it is
 * left inactive and the reason is reported, so a site never gets a booking button that leads nowhere: the CTA resolver reads
 * the live state, and without a bookable type the student inquiry form stays the primary path.
 *
 * Idempotent. It never deletes anything, never touches a booking type the blueprint did not install, and "no" changes nothing
 * (an owner who already runs Calendar bookings turns them off in Calendar, not in a website questionnaire).
 */
final class ConsultationBookingSetup
{
    public const STATE_NOT_ASKED = 'not_asked';

    public const STATE_DECLINED = 'declined';

    public const STATE_NOT_INSTALLED = 'not_installed';

    public const STATE_READY = 'ready';

    public const STATE_NOT_READY = 'not_ready';

    /** day key => day_of_week (0 = Sunday, as StaffAvailabilityRule stores it). */
    public const DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** window key => [start, end] local time. */
    public const WINDOWS = [
        'morning' => ['09:00', '12:00'],
        'afternoon' => ['13:00', '17:00'],
        'after_school' => ['15:30', '19:00'],
        'business_hours' => ['09:00', '17:00'],
    ];

    /** option key => minutes (the questionnaire offers these keys; they are never numeric so a stored answer is unambiguous). */
    public const DURATIONS = ['m15' => 15, 'm30' => 30, 'm45' => 45, 'm60' => 60];

    public const NOTICES = ['none' => 0, 'h1' => 60, 'h4' => 240, 'd1' => 1440, 'd2' => 2880];

    public function __construct(
        private readonly BookingTypeManager $bookingTypes,
        private readonly StaffAvailabilityService $availability,
    ) {
    }

    /** The booking type THIS business's blueprint installed, or null. */
    public function installedType(Business $business): ?BookingType
    {
        $ids = BusinessBlueprintComponentInstallation::query()
            ->where('business_id', $business->id)
            ->where('component_type', 'booking_type')
            ->where('state', 'installed')
            ->whereNotNull('installed_record_id')
            ->orderBy('id')
            ->pluck('installed_record_id');

        if ($ids->isEmpty()) {
            return null;
        }

        $locationIds = BusinessLocation::query()->where('business_id', $business->id)->pluck('id');

        return BookingType::query()->whereIn('id', $ids)->whereIn('business_location_id', $locationIds)->orderBy('id')->first();
    }

    /**
     * @param  array{enabled?: mixed, duration_minutes?: mixed, minimum_notice_minutes?: mixed, days?: mixed, hours?: mixed}  $settings  the owner's answers
     * @return array{state: string, reason: ?string, booking_type_uid: ?string}
     */
    public function apply(Business $business, array $settings, int $actorUserId): array
    {
        if (! array_key_exists('enabled', $settings)) {
            return ['state' => self::STATE_NOT_ASKED, 'reason' => null, 'booking_type_uid' => null];
        }

        if (! $this->truthy($settings['enabled'])) {
            return ['state' => self::STATE_DECLINED, 'reason' => null, 'booking_type_uid' => null];
        }

        $type = $this->installedType($business);

        if ($type === null) {
            return ['state' => self::STATE_NOT_INSTALLED, 'reason' => 'Your consultation booking type has not been installed for this business yet, so the website keeps using your inquiry form.', 'booking_type_uid' => null];
        }

        $location = $type->location;
        $workspace = Workspace::query()->find($business->workspace_id);

        if ($location === null || $workspace === null) {
            return ['state' => self::STATE_NOT_READY, 'reason' => 'The booking type has no location to take bookings at.', 'booking_type_uid' => $type->public_booking_uuid];
        }

        return DB::transaction(function () use ($business, $settings, $actorUserId, $type, $location, $workspace) {
            // Length and notice: only what the owner chose, within what the Calendar accepts.
            $changes = [];
            $duration = self::DURATIONS[(string) ($settings['duration_minutes'] ?? '')] ?? null;
            if ($duration !== null) {
                $changes['duration_minutes'] = $duration;
            }
            $notice = self::NOTICES[(string) ($settings['minimum_notice_minutes'] ?? '')] ?? null;
            if ($notice !== null) {
                $changes['minimum_notice_minutes'] = $notice;
            }

            if ($changes !== []) {
                $this->bookingTypes->update($type, ['name' => $type->name, 'description' => $type->description, 'color' => $type->color, 'duration_minutes' => $changes['duration_minutes'] ?? $type->duration_minutes] + $changes);
                $type = $type->fresh();
            }

            // The owner takes the consultations: assigned only if the Calendar's own eligibility rule accepts them.
            $assigned = $this->bookingTypes->syncStaff($type, [$actorUserId]);
            $week = $this->week($settings);

            if ($assigned['attached'] !== [] && $week !== []) {
                $this->availability->replaceWeek($workspace, $business, $location, $actorUserId, $week, $actorUserId);
            }

            // Turn it on only if a guest would really reach a working page; otherwise leave it exactly as it was.
            $this->bookingTypes->setActive($type, true);
            $readiness = $this->bookingTypes->publicBookingReadiness($type->fresh());

            if (! $readiness['ready']) {
                $this->bookingTypes->setActive($type, false);

                return ['state' => self::STATE_NOT_READY, 'reason' => $this->friendly($assigned, $week, (string) $readiness['reason']), 'booking_type_uid' => $type->public_booking_uuid];
            }

            return ['state' => self::STATE_READY, 'reason' => null, 'booking_type_uid' => $type->public_booking_uuid];
        });
    }

    /**
     * What the owner's days and window mean as weekly hours: day_of_week => windows.
     *
     * @return array<int, list<array{start: string, end: string}>>
     */
    private function week(array $settings): array
    {
        $window = self::WINDOWS[(string) ($settings['hours'] ?? '')] ?? null;
        $days = array_values(array_filter((array) ($settings['days'] ?? []), fn ($day) => is_string($day) && isset(self::DAYS[$day])));

        if ($window === null || $days === []) {
            return [];
        }

        $week = [];
        foreach (array_unique($days) as $day) {
            $week[self::DAYS[$day]] = [['start' => $window[0], 'end' => $window[1]]];
        }

        return $week;
    }

    private function friendly(array $assigned, array $week, string $calendarReason): string
    {
        if ($assigned['attached'] === []) {
            return 'You could not be assigned to take consultations at that location, so online booking is off and your inquiry form stays the main button.';
        }

        if ($week === []) {
            return 'Choose the days and the hours you can take a consultation. Until then online booking is off and your inquiry form stays the main button.';
        }

        return $calendarReason . ' Online booking is off and your inquiry form stays the main button.';
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
    }
}
