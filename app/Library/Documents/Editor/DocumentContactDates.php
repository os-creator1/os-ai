<?php

namespace App\Library\Documents\Editor;

use App\Enums\Calendar\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\ContactGroupFields;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 17B §7 — date-prefill CANDIDATES for the document's
 * Contact. Never forced and never guessed: a candidate exists only when the
 * Business already holds the fact, and each one names its source.
 *
 *   1. the Contact's next scheduled Appointment (start_at in the future,
 *      earliest first, in a Location of this Business);
 *   2. a date-typed custom field of the Contact's group, only when the group
 *      has exactly one such field and the Contact has a value that parses.
 *
 * Dates are the calendar day in the Business timezone.
 */
final class DocumentContactDates
{
    /**
     * @return array<int, array{source: string, label: string, date: string, iso: string}>
     */
    public function candidates(BusinessDocument $document): array
    {
        $business = Business::query()->find($document->business_id);
        $contact = Contacts::query()->where('business_id', $document->business_id)->find($document->contact_id);

        if ($business === null || $contact === null) {
            return [];
        }

        $zone = $this->zone((string) $business->timezone);
        $out = [];

        $appointment = Appointment::query()
            ->where('contact_id', $contact->id)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('start_at', '>', now())
            ->whereIn('business_location_id', $business->locations()->select('business_locations.id'))
            ->orderBy('start_at')
            ->first();

        if ($appointment !== null) {
            $out[] = $this->candidate('appointment', 'Next appointment', Carbon::instance($appointment->start_at), $zone);
        }

        $fields = ContactGroupFields::query()
            ->where('contact_group_id', $contact->group_id)
            ->whereIn('type', [ContactGroupFields::TYPE_DATE, ContactGroupFields::TYPE_DATETIME])
            ->get();

        if ($fields->count() === 1) {
            $value = ContactsCustomField::query()
                ->where('contact_id', $contact->id)
                ->where('field_id', $fields->first()->id)
                ->value('value');

            $date = $this->parse($value, $zone);
            if ($date !== null) {
                $out[] = $this->candidate('custom_field', (string) $fields->first()->label, $date, $zone);
            }
        }

        return $out;
    }

    /**
     * @return array{source: string, label: string, date: string, iso: string}
     */
    private function candidate(string $source, string $label, Carbon $at, \DateTimeZone $zone): array
    {
        $local = $at->copy()->setTimezone($zone);

        return ['source' => $source, 'label' => $label, 'date' => $local->format('Y-m-d'), 'iso' => $local->toIso8601String()];
    }

    private function parse(mixed $value, \DateTimeZone $zone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), $zone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function zone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return new \DateTimeZone((string) config('app.timezone', 'UTC'));
        }
    }
}
