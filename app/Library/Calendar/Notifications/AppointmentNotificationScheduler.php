<?php

namespace App\Library\Calendar\Notifications;

use App\Enums\Calendar\AppointmentStatus;
use App\Jobs\Calendar\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking Notifications V1 — decides WHICH notifications an appointment owes and
 * writes them to the ledger. It sends nothing: AppointmentNotificationSender
 * delivers a row, AppointmentNotificationDispatcher finds rows that are due.
 *
 * AUTHORITY. The Calendar owns the built-in transactional confirmation and
 * reminders. Business Automations may still react to the same appointment events
 * for OPTIONAL workflows; they never produce these messages and this class never
 * reads an Automation. The two are separate on purpose.
 *
 * IDEMPOTENCY. Every row is inserted with INSERT IGNORE against
 * UNIQUE(appointment_id, channel, occurrence_key), so a replayed booking, a
 * redelivered event and a re-run of this class create nothing twice. Reminder keys
 * carry the start they were scheduled for, so a reschedule yields NEW keys
 * (new reminders) and leaves the old rows recognisable as obsolete.
 *
 * RECIPIENT SNAPSHOT. The email, phone and SMS consent are those the guest gave on
 * THIS booking, frozen on every row. They are never re-read from the Contact, so a
 * returning Contact's stale email is never used and a later Contact edit never
 * rewrites who a scheduled reminder goes to.
 */
class AppointmentNotificationScheduler
{
    /**
     * Record everything a successful public booking owes, then hand the due
     * confirmations to the queue. Never throws into the booking: the caller still
     * treats this as best-effort, and the sweep re-dispatches any confirmation
     * whose job was lost.
     */
    public function recordPublicBooking(
        Appointment $appointment,
        BookingType $type,
        string $email,
        string $phone,
        bool $smsConsent,
    ): void {
        $location = BusinessLocation::query()->find($appointment->business_location_id);

        if ($location === null) {
            return;
        }

        $now = Carbon::now('UTC');
        $channels = [];

        if ($type->notifiesByEmail()) {
            $channels[AppointmentNotification::CHANNEL_EMAIL] = null;
        }

        if ($type->notifiesBySms()) {
            $channels[AppointmentNotification::CHANNEL_SMS] = $smsConsent ? $now : null;
        }

        $confirmationIds = [];

        foreach ($channels as $channel => $consentAt) {
            $base = [
                'business_id' => (int) $location->business_id,
                'business_location_id' => (int) $appointment->business_location_id,
                'appointment_id' => (int) $appointment->id,
                'contact_id' => $appointment->contact_id === null ? null : (int) $appointment->contact_id,
                'channel' => $channel,
                'recipient_email' => trim($email) === '' ? null : mb_substr(trim($email), 0, 190),
                'recipient_phone' => trim($phone) === '' ? null : mb_substr(trim($phone), 0, 32),
                'sms_consent_at' => $channel === AppointmentNotification::CHANNEL_SMS ? $consentAt : null,
            ];

            $this->insertOnce($base + [
                'kind' => AppointmentNotification::KIND_CONFIRMATION,
                'occurrence_key' => 'confirmation',
                'offset_minutes' => null,
                'appointment_start_at' => $appointment->start_at,
                'scheduled_for' => $now,
            ], $now);

            $row = AppointmentNotification::query()
                ->where('appointment_id', $appointment->id)->where('channel', $channel)
                ->where('occurrence_key', 'confirmation')->first();

            if ($row !== null && $row->status === AppointmentNotification::STATUS_PENDING) {
                $confirmationIds[] = (int) $row->id;
            }

            foreach ($type->reminderOffsetMinutes() as $offset) {
                $this->insertReminder($base, $appointment->start_at, $offset, $now);
            }
        }

        foreach ($confirmationIds as $id) {
            $this->dispatch($id);
        }
    }

    /**
     * The appointment moved. Reminders bound to the old start are obsolete; new
     * ones are scheduled for the new start, for every channel and offset the
     * appointment already owed. Safe to run any number of times, and always reads
     * the appointment's CURRENT start, so two quick moves converge on the last.
     */
    public function onRescheduled(int $appointmentId): void
    {
        $appointment = Appointment::query()->find($appointmentId);

        if ($appointment === null || $appointment->status !== AppointmentStatus::Scheduled) {
            return;
        }

        $now = Carbon::now('UTC');
        $start = $appointment->start_at->copy()->utc();

        DB::transaction(function () use ($appointment, $start, $now): void {
            // 1. Pending reminders bound to any OTHER start are obsolete.
            AppointmentNotification::query()
                ->where('appointment_id', $appointment->id)
                ->where('kind', AppointmentNotification::KIND_REMINDER)
                ->where('status', AppointmentNotification::STATUS_PENDING)
                ->where('appointment_start_at', '!=', $start)
                ->update(['status' => AppointmentNotification::STATUS_CANCELLED, 'reason' => 'rescheduled', 'updated_at' => $now]);

            // 2. Re-schedule each (channel, offset) the appointment owed, for the new start.
            $rows = AppointmentNotification::query()
                ->where('appointment_id', $appointment->id)
                ->orderBy('id')
                ->get();

            $offsets = $rows->where('kind', AppointmentNotification::KIND_REMINDER)
                ->pluck('offset_minutes')->filter()->unique()->map(fn ($m): int => (int) $m)->values();

            foreach ($rows->groupBy('channel') as $channelRows) {
                $anchor = $channelRows->first();
                $base = [
                    'business_id' => (int) $anchor->business_id,
                    'business_location_id' => (int) $anchor->business_location_id,
                    'appointment_id' => (int) $anchor->appointment_id,
                    'contact_id' => $anchor->contact_id === null ? null : (int) $anchor->contact_id,
                    'channel' => $anchor->channel,
                    'recipient_email' => $anchor->recipient_email,
                    'recipient_phone' => $anchor->recipient_phone,
                    'sms_consent_at' => $anchor->sms_consent_at,
                ];

                foreach ($offsets as $offset) {
                    $this->insertReminder($base, $start, $offset, $now);
                }
            }
        });
    }

    /** The appointment was cancelled: nothing still pending is ever sent. */
    public function onCancelled(int $appointmentId): void
    {
        AppointmentNotification::query()
            ->where('appointment_id', $appointmentId)
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->update([
                'status' => AppointmentNotification::STATUS_CANCELLED,
                'reason' => 'appointment_cancelled',
                'updated_at' => Carbon::now('UTC'),
            ]);
    }

    public function dispatch(int $notificationId): void
    {
        // Mark first so the sweep does not also dispatch it; the sender's own
        // claim is what guarantees a single send.
        AppointmentNotification::query()->whereKey($notificationId)
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->update(['dispatched_at' => Carbon::now('UTC')]);

        SendAppointmentNotification::dispatch($notificationId);
    }

    /** @param  array<string, mixed>  $base */
    private function insertReminder(array $base, Carbon $start, int $offset, Carbon $now): void
    {
        $start = $start->copy()->utc();
        $key = 'reminder:' . $offset . ':' . $start->format('YmdHi');
        $scheduledFor = $start->copy()->subMinutes($offset);
        $future = $scheduledFor->greaterThan($now);

        $this->insertOnce($base + [
            'kind' => AppointmentNotification::KIND_REMINDER,
            'occurrence_key' => $key,
            'offset_minutes' => $offset,
            'appointment_start_at' => $start,
            // A reminder whose moment has already passed is recorded as skipped,
            // never sent late.
            'scheduled_for' => $scheduledFor,
        ], $now, $future ? null : 'past_due');

        // The appointment moved back to a start it held before: its reminder row
        // exists, cancelled as obsolete. It is owed again.
        if ($future) {
            AppointmentNotification::query()
                ->where('appointment_id', $base['appointment_id'])
                ->where('channel', $base['channel'])
                ->where('occurrence_key', $key)
                ->where('status', AppointmentNotification::STATUS_CANCELLED)
                ->where('reason', 'rescheduled')
                ->update(['status' => AppointmentNotification::STATUS_PENDING, 'reason' => null, 'dispatched_at' => null, 'updated_at' => $now]);
        }
    }

    /**
     * INSERT IGNORE on the unique occurrence. The initial status is decided here
     * from facts that can never change for this row (no consent, no address); the
     * facts that can change (is the channel ready, is the Contact still
     * subscribed) are decided at send time.
     *
     * @param  array<string, mixed>  $row
     */
    private function insertOnce(array $row, Carbon $now, ?string $forcedSkip = null): void
    {
        $reason = $forcedSkip ?? $this->staticSkipReason($row);

        DB::table('appointment_notifications')->insertOrIgnore($row + [
            'status' => $reason === null ? AppointmentNotification::STATUS_PENDING : AppointmentNotification::STATUS_SKIPPED,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    private function staticSkipReason(array $row): ?string
    {
        if ($row['channel'] === AppointmentNotification::CHANNEL_EMAIL) {
            return blank($row['recipient_email'] ?? null) ? 'no_email' : null;
        }

        if (blank($row['recipient_phone'] ?? null)) {
            return 'no_phone';
        }

        return ($row['sms_consent_at'] ?? null) === null ? 'no_sms_consent' : null;
    }
}
