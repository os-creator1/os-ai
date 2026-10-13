<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Calendar\CalendarScheduleService;
use App\Library\Dashboard\Home\HomeBookingReader;
use Carbon\CarbonImmutable;

/** Upcoming appointments (next 14 days) and booking types, over the Locations this actor may reach. */
final class ScheduleTool implements ReadTool
{
    public function __construct(
        private readonly CalendarScheduleService $schedule,
        private readonly HomeBookingReader $booking,
    ) {
    }

    public function key(): string
    {
        return 'schedule';
    }

    public function description(): string
    {
        return 'Upcoming appointments in the next 14 days and the booking types (services) set up, with whether each can take bookings. No args.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('calendar') && $context->can('access_backend');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $tz = $context->timezone();
        $summary = $this->booking->summary($context->business, (int) $context->user->id);
        $from = CarbonImmutable::now();
        $to = $from->addDays(14);
        $rows = [];
        $links = [];
        [$workspaceUid, $businessUid] = $context->scoped;

        foreach ($this->schedule->accessibleLocations($context->business, (int) $context->user->id)->take(5) as $location) {
            $appointments = $this->schedule->appointmentsForRange($location, $from, $to)->filter(fn ($a): bool => $a->status->value === 'scheduled')->take(10);
            $contacts = $this->schedule->contactSummaries($context->business, $appointments);

            foreach ($appointments as $a) {
                $contact = $contacts[(int) $a->contact_id] ?? null;
                $start = CarbonImmutable::parse($a->start_at)->utc()->setTimezone($tz);
                $rows[] = [
                    'when' => $start->format('D j M, g:ia'),
                    'type' => (string) ($a->bookingType->name ?? 'Appointment'),
                    'with' => $contact['name'] ?? $contact['phone'] ?? 'Customer',
                    'location' => (string) $location->name,
                    'sort' => $start->getTimestamp(),
                ];
                $links[] = ['label' => 'Appointment · ' . $start->format('D j M'), 'url' => route('customer.workspaces.businesses.calendar.appointments.show', [$workspaceUid, $businessUid, $location->uid, $a->uid])];
            }
        }

        usort($rows, fn (array $x, array $y): int => $x['sort'] <=> $y['sort']);
        $rows = array_map(fn (array $r): array => array_diff_key($r, ['sort' => 1]), array_slice($rows, 0, 8));

        $data = [
            'timezone' => $tz,
            'upcoming_appointments' => $rows,
            'booking_types_total' => $summary['typeCount'],
            'booking_types_active' => $summary['activeTypeCount'],
            'booking_types_ready_for_public_booking' => $summary['readyCount'],
            'booking_type_problems' => array_slice(array_map(fn (array $p): array => ['name' => $p['name'], 'reason' => $p['reason']], $summary['problems']), 0, 3),
            'accessible_locations' => $summary['locations']->count(),
        ];
        $facts = [
            ['label' => 'Upcoming appointments (14 days)', 'value' => (string) count($rows)],
            ['label' => 'Booking types ready to take bookings', 'value' => $summary['readyCount'] . ' of ' . $summary['activeTypeCount'] . ' active'],
        ];

        return new ToolResult($this->key(), $data, $facts, array_slice($links, 0, 4), $summary['locations']->isEmpty() ? ['No Location is available to you for scheduling.'] : []);
    }
}
