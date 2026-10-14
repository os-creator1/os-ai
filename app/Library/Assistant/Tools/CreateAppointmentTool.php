<?php

namespace App\Library\Assistant\Tools;

use App\Exceptions\Calendar\BookingRefusedException;
use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantMissingInformation;
use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\CalendarScheduleService;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Book an appointment for an EXISTING contact, through AppointmentBookingService (round-robin staff) — the engine
 * that owns conflict detection, availability and the booking notifications. Only automatic staff assignment is
 * supported; the engine, not this tool, decides whether the slot is free.
 */
final class CreateAppointmentTool implements ActionTool
{
    public function __construct(
        private readonly AppointmentBookingService $booking,
        private readonly BookingTypeManager $types,
        private readonly CalendarLocationResolver $locations,
        private readonly CalendarScheduleService $schedule,
    ) {
    }

    public function key(): string
    {
        return 'create_appointment';
    }

    public function description(): string
    {
        return 'Book an appointment for an existing contact. args: contact (name or phone to find them), service (booking type name, optional if only one), date (YYYY-MM-DD, resolve "Friday" etc. from today), time (HH:MM 24h), location (optional name). Staff is assigned automatically.';
    }

    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'min:2', 'max:80'],
            'service' => ['nullable', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function mode(): string
    {
        return self::MODE_EXECUTE;
    }

    public function available(AssistantContext $context): bool
    {
        return $context->action('appointment') !== null;
    }

    public function prepare(AssistantContext $context, array $args): ActionPreview
    {
        $tz = $context->timezone();
        $start = Carbon::createFromFormat('!Y-m-d H:i', $args['date'] . ' ' . $args['time'], $tz);

        if ($start->isPast()) {
            throw new AssistantActionRefused('in_the_past', 'That time has already passed. Tell me a future date and time.');
        }

        $location = $this->resolveLocation($context, $args['location'] ?? null);
        $type = $this->resolveType($location, $args['service'] ?? null);
        $contact = $this->resolveContact($context, $location, (string) $args['contact']);

        $name = $contact['name'] ?: $contact['phone'];

        return new ActionPreview(
            title: 'Book appointment',
            lines: [
                ['label' => 'Customer', 'value' => $name . ($contact['name'] ? ' · ' . $contact['phone'] : '')],
                ['label' => 'Service', 'value' => (string) $type->name],
                ['label' => 'When', 'value' => $start->format('l j F Y, g:ia') . ' (' . $tz . ')'],
                ['label' => 'Location', 'value' => (string) ($location->name ?: 'Main location')],
                ['label' => 'Staff', 'value' => 'Assigned automatically from who is free'],
            ],
            effects: array_values(array_filter([
                'The appointment is added to your calendar if the time is still free; if it is not, nothing is booked and you will be told why.',
                // The assistant's booking path writes no confirmation or reminder (only the public booking flow does), whatever the
                // type's notification settings say, so the preview must never promise one.
                ($type->notifiesByEmail() || $type->notifiesBySms())
                    ? 'This service sends automatic confirmations for online bookings, but not for bookings made here, so ' . $name . ' will NOT be sent a confirmation or reminder automatically. Let them know yourself.'
                    : 'No confirmation or reminder is sent to the customer by this action.',
            ])),
            recipients: [],
            cost: null,
            resolved: [
                'location_uid' => (string) $location->uid,
                'booking_type_uid' => (string) $type->uid,
                'contact_uid' => (string) $contact['uid'],
                'start_utc' => $start->copy()->utc()->format('Y-m-d H:i:s'),
            ],
            confirmLabel: 'Book appointment',
        );
    }

    public function fingerprint(AssistantContext $context, array $resolved): string
    {
        $location = $this->locationOrRefuse($context, (string) $resolved['location_uid']);
        $type = $this->types->findForLocation($location, (string) $resolved['booking_type_uid']);

        if ($type === null || ! $type->isActive()) {
            throw new AssistantActionRefused('type_changed', 'That booking type is no longer available, so nothing was booked.');
        }

        $contact = $this->schedule->findBookableContact($context->business, $location, (string) $resolved['contact_uid']);

        if ($contact === null) {
            throw new AssistantActionRefused('contact_changed', 'That customer can no longer be booked at this location, so nothing was booked.');
        }

        // Everything the preview described: the service as it is NOW (length), the customer, the slot.
        return hash('sha256', json_encode([(int) $type->id, (int) $type->duration_minutes, (int) $contact->id, $resolved['start_utc']]));
    }

    public function execute(AssistantContext $context, array $resolved): ActionOutcome
    {
        $location = $this->locationOrRefuse($context, (string) $resolved['location_uid']);
        $type = $this->types->findForLocation($location, (string) $resolved['booking_type_uid']);
        $contact = $this->schedule->findBookableContact($context->business, $location, (string) $resolved['contact_uid']);

        if ($type === null || ! $type->isActive() || $contact === null) {
            throw new AssistantActionRefused('changed', 'Something about this booking changed, so nothing was booked.');
        }

        try {
            $appointment = $this->booking->bookWithRoundRobin($type, (int) $contact->id, CarbonImmutable::parse($resolved['start_utc'], 'UTC'), (int) $context->user->id);
        } catch (BookingRefusedException $refusal) {
            // The engine's own, customer-safe refusal (slot taken, nobody free, type not bookable).
            throw new AssistantActionRefused('booking_refused', $refusal->getMessage() !== '' ? $refusal->getMessage() : 'That time could not be booked.');
        }

        [$workspaceUid, $businessUid] = $context->scoped;

        return new ActionOutcome(
            'The appointment was booked.',
            route('customer.workspaces.businesses.calendar.appointments.show', [$workspaceUid, $businessUid, $location->uid, $appointment->uid]),
            ['appointment_uid' => (string) $appointment->uid],
        );
    }

    private function resolveLocation(AssistantContext $context, ?string $named): BusinessLocation
    {
        $accessible = $this->schedule->accessibleLocations($context->business, (int) $context->user->id)->filter(fn ($l) => $l->isActive())->values();

        if ($accessible->isEmpty()) {
            throw new AssistantActionRefused('no_location', 'There is no location you can book appointments at.');
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

        throw new AssistantMissingInformation(['Which location is this appointment at?'], $accessible->map(fn ($l): string => (string) ($l->name ?: 'Main location'))->all());
    }

    private function resolveType(BusinessLocation $location, ?string $service): BookingType
    {
        $active = $this->types->forLocation($location)->filter(fn (BookingType $t): bool => $t->isActive())->values();

        if ($active->isEmpty()) {
            throw new AssistantActionRefused('no_booking_type', 'There is no active booking type at this location yet. I can help you create one first.');
        }

        if ($service !== null && trim($service) !== '') {
            $needle = mb_strtolower(trim($service));
            $exact = $active->filter(fn (BookingType $t): bool => mb_strtolower((string) $t->name) === $needle)->values();
            $partial = $active->filter(fn (BookingType $t): bool => str_contains(mb_strtolower((string) $t->name), $needle) || str_contains($needle, mb_strtolower((string) $t->name)))->values();
            $pick = $exact->count() === 1 ? $exact : $partial;

            if ($pick->count() === 1) {
                return $pick->first();
            }
        }

        if ($active->count() === 1) {
            return $active->first();
        }

        throw new AssistantMissingInformation(['Which service is this appointment for?'], $active->map(fn (BookingType $t): string => (string) $t->name)->all());
    }

    /** @return array{uid: string, name: ?string, phone: string} */
    private function resolveContact(AssistantContext $context, BusinessLocation $location, string $query): array
    {
        $found = $this->schedule->searchBookableContacts($context->business, $location, $query, 5);

        if ($found === []) {
            throw new AssistantMissingInformation(["I couldn't find a contact matching \"{$query}\". Can you give me their full name or phone number?"]);
        }

        if (count($found) > 1) {
            throw new AssistantMissingInformation(['Which of these customers do you mean?'], array_map(fn (array $c): string => trim(($c['name'] ?? '') . ' ' . $c['phone']), $found));
        }

        return $found[0];
    }

    private function locationOrRefuse(AssistantContext $context, string $uid): BusinessLocation
    {
        return $this->locations->resolveForActor($context->business, $uid, (int) $context->user->id)
            ?? throw new AssistantActionRefused('location_unreachable', 'You no longer have access to that location, so nothing was changed.');
    }
}
