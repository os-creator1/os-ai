<?php

namespace App\Library\Dashboard\Home;

/**
 * Home V2 STATE 1 — the guided setup journey.
 *
 * Every step is "done" only because a persisted fact says so — a Location
 * row, a booking type, BookingTypeManager's own readiness verdict, the website
 * status column. Nothing is stored on the browser and no percentage is
 * invented: progress is "N of M steps", counted from those facts.
 *
 * ESSENTIAL steps decide whether Home shows the journey at all (the Business
 * cannot yet do what it is for). Non-essential steps (growth) are listed so
 * the journey ends somewhere useful, but never keep a working Business in
 * setup mode.
 *
 * A pure function: callers pass facts and already-gated URLs, so a step whose
 * fix this actor cannot reach simply carries no action (never a dead link).
 */
final class HomeSetupJourney
{
    /**
     * @param  array{
     *   hasLocation: bool,
     *   calendar: bool,
     *   activeTypeCount: int,
     *   readyCount: int,
     *   problemReason: ?string,
     *   websiteStatus: ?string,
     *   websiteApplicable: bool,
     * }  $facts
     * @param  array{locations: ?string, bookingType: ?string, fixBooking: ?string, website: ?string}  $urls
     * @return array{steps: array<int, array<string, mixed>>, essentialIncomplete: bool, done: int, total: int, nextKey: ?string}
     */
    public function build(array $facts, array $urls): array
    {
        $steps = [];

        $steps[] = [
            'key' => 'location',
            'title' => 'Confirm your business location',
            'outcome' => 'MotionGrove knows where you operate, so bookings and messages belong to the right place.',
            'done' => $facts['hasLocation'],
            'essential' => true,
            'action' => $urls['locations'] === null ? null : ['label' => 'Confirm location', 'url' => $urls['locations'], 'drawer' => null],
        ];

        if ($facts['calendar']) {
            $steps[] = [
                'key' => 'booking_type',
                'title' => 'Add your first service',
                'outcome' => 'Customers can only book something you offer — a consultation, a visit, a class.',
                'done' => $facts['activeTypeCount'] > 0,
                'essential' => true,
                'action' => $facts['hasLocation'] && $urls['bookingType'] !== null
                    ? ['label' => 'Create a booking type', 'url' => $urls['bookingType'], 'drawer' => 'booking-type']
                    : null,
            ];

            $steps[] = [
                'key' => 'bookable',
                'title' => 'Make bookings possible',
                'outcome' => 'Someone is assigned and has working hours, so real times can be offered.',
                'done' => $facts['readyCount'] > 0,
                'essential' => true,
                'note' => $facts['activeTypeCount'] > 0 && $facts['readyCount'] === 0 ? $facts['problemReason'] : null,
                'action' => $facts['activeTypeCount'] > 0 && $urls['fixBooking'] !== null
                    ? ['label' => 'Finish booking setup', 'url' => $urls['fixBooking'], 'drawer' => null]
                    : null,
            ];
        }

        if ($facts['websiteApplicable']) {
            $steps[] = [
                'key' => 'website',
                'title' => 'Launch your website',
                'outcome' => 'People searching for you can find and contact you online.',
                'done' => $facts['websiteStatus'] === 'published',
                'essential' => false,
                'action' => $urls['website'] === null ? null : ['label' => 'Open website', 'url' => $urls['website'], 'drawer' => null],
            ];
        }

        $essential = array_values(array_filter($steps, fn (array $s): bool => $s['essential']));
        $incomplete = array_values(array_filter($essential, fn (array $s): bool => ! $s['done']));
        $next = null;

        foreach ($steps as $step) {
            if (! $step['done']) {
                $next = $step['key'];

                break;
            }
        }

        return [
            'steps' => $steps,
            'essentialIncomplete' => $incomplete !== [],
            'done' => count(array_filter($steps, fn (array $s): bool => $s['done'])),
            'total' => count($steps),
            'nextKey' => $next,
        ];
    }
}
