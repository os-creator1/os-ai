<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantMissingInformation;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\CalendarScheduleService;
use App\Models\BookingType;
use App\Models\BusinessLocation;

/** Create a booking type (a bookable service) at a Location, through BookingTypeManager — the same write the Calendar page performs. */
final class CreateBookingTypeTool implements ActionTool
{
    public function __construct(
        private readonly BookingTypeManager $types,
        private readonly CalendarLocationResolver $locations,
        private readonly CalendarScheduleService $schedule,
    ) {
    }

    public function key(): string
    {
        return 'create_booking_type';
    }

    public function description(): string
    {
        return 'Create a bookable service (booking type), e.g. "3-hour wedding package". args: name (string), duration_minutes (int 1-1440), description (optional), location (optional name, only needed if several locations).';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function mode(): string
    {
        return self::MODE_EXECUTE;
    }

    public function available(AssistantContext $context): bool
    {
        return $context->action('booking-type') !== null;
    }

    public function prepare(AssistantContext $context, array $args): ActionPreview
    {
        $location = $this->resolveLocation($context, $args['location'] ?? null);
        $name = trim((string) $args['name']);

        if ($this->types->forLocation($location)->contains(fn (BookingType $t): bool => mb_strtolower((string) $t->name) === mb_strtolower($name))) {
            throw new AssistantActionRefused('duplicate_name', "There is already a booking type called \"{$name}\" at {$location->name}. Choose a different name, or edit the existing one.");
        }

        $minutes = (int) $args['duration_minutes'];

        return new ActionPreview(
            title: 'Create booking type',
            lines: array_values(array_filter([
                ['label' => 'Name', 'value' => $name],
                ['label' => 'Length', 'value' => self::duration($minutes)],
                ['label' => 'Location', 'value' => (string) ($location->name ?: 'Main location')],
                ! empty($args['description']) ? ['label' => 'Description', 'value' => (string) $args['description']] : null,
            ])),
            effects: [
                'A new booking type is added to your calendar.',
                'It can take public bookings only once staff are assigned to it. Nothing is sent to anyone and nothing is charged.',
            ],
            recipients: [],
            cost: null,
            resolved: ['location_uid' => (string) $location->uid, 'name' => $name, 'duration_minutes' => $minutes, 'description' => $args['description'] ?? null],
            confirmLabel: 'Create booking type',
        );
    }

    public function fingerprint(AssistantContext $context, array $resolved): string
    {
        $location = $this->locationOrRefuse($context, (string) $resolved['location_uid']);
        $taken = $this->types->forLocation($location)->contains(fn (BookingType $t): bool => mb_strtolower((string) $t->name) === mb_strtolower((string) $resolved['name']));

        return hash('sha256', json_encode([(int) $location->id, $taken]));
    }

    public function execute(AssistantContext $context, array $resolved): ActionOutcome
    {
        $location = $this->locationOrRefuse($context, (string) $resolved['location_uid']);
        $created = $this->types->create($location, [
            'name' => $resolved['name'],
            'description' => $resolved['description'] ?? null,
            'duration_minutes' => $resolved['duration_minutes'],
        ], (int) $context->user->id);

        // Read back what the module actually stored — success is only claimed from this.
        $stored = BookingType::query()->whereKey($created->getKey())->first();

        if ($stored === null || mb_strtolower((string) $stored->name) !== mb_strtolower((string) $resolved['name'])) {
            throw new AssistantActionRefused('not_verified', 'The booking type could not be confirmed after saving, so it was not reported as created.');
        }

        [$workspaceUid, $businessUid] = $context->scoped;

        return new ActionOutcome(
            "Booking type \"{$stored->name}\" was created.",
            route('customer.workspaces.businesses.calendar.booking-types.edit', [$workspaceUid, $businessUid, $location->uid, $stored->uid]),
            ['booking_type_uid' => (string) $stored->uid],
        );
    }

    private function resolveLocation(AssistantContext $context, ?string $named): BusinessLocation
    {
        $accessible = $this->schedule->accessibleLocations($context->business, (int) $context->user->id)->filter(fn ($l) => $l->isActive())->values();

        if ($accessible->isEmpty()) {
            throw new AssistantActionRefused('no_location', 'There is no location you can add a booking type to.');
        }

        if ($named !== null && trim($named) !== '') {
            $match = $accessible->filter(fn ($l): bool => str_contains(mb_strtolower((string) $l->name), mb_strtolower(trim($named))))->values();

            if ($match->count() === 1) {
                return $match->first();
            }
        }

        if ($accessible->count() === 1) {
            return $accessible->first();
        }

        throw new AssistantMissingInformation(['Which location should this booking type be for?'], $accessible->map(fn ($l): string => (string) ($l->name ?: 'Main location'))->all());
    }

    private function locationOrRefuse(AssistantContext $context, string $uid): BusinessLocation
    {
        // Re-derived through the module's own Location resolver; a stale or unreachable uid is a refusal.
        return $this->locations->resolveForActor($context->business, $uid, (int) $context->user->id)
            ?? throw new AssistantActionRefused('location_unreachable', 'You no longer have access to that location, so nothing was changed.');
    }

    private static function duration(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $h = intdiv($minutes, 60);

            return $h . ($h === 1 ? ' hour' : ' hours');
        }

        return $minutes . ' minutes';
    }
}
