<?php

namespace App\Library\Calendar\Notifications;

use App\Enums\Business\BusinessStatus;
use App\Enums\Calendar\AppointmentStatus;
use App\Library\Messaging\BusinessSmsSendingPath;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\Contacts;
use App\Models\User;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Booking Notifications V1 — delivers ONE ledger row, at most once.
 *
 * AT-MOST-ONCE. The first thing deliver() does is flip the row pending -> sending
 * with a conditional UPDATE. Only the process that wins that update proceeds, so a
 * redelivered or retried job, a second worker and a re-run sweep all find the row
 * already claimed and do nothing. A crash after the claim leaves the row
 * `sending`: the outcome is unknown and it is never re-sent (the job runs once).
 *
 * EVERYTHING IS RE-CHECKED AT SEND TIME, never trusted from scheduling time:
 *   - the appointment must still be `scheduled`;
 *   - a reminder must still be bound to the appointment's CURRENT start;
 *   - a reminder whose appointment has begun is never sent;
 *   - for SMS: the Contact is still subscribed (an opt-out between booking and
 *     reminder stops the reminder), the booking-time transactional consent exists,
 *     and the canonical Location-aware sending path is ready.
 *
 * NEVER FAILS A BOOKING. This runs after the booking committed; every refusal is
 * a recorded `skipped` + reason code, every provider failure a recorded `failed`.
 */
class AppointmentNotificationSender
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly BusinessSmsSendingPath $paths,
    ) {
    }

    public function deliver(int $notificationId): void
    {
        $now = Carbon::now('UTC');

        // Not yet due (an early or duplicated job): leave it pending for its time.
        $due = AppointmentNotification::query()->whereKey($notificationId)
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->where('scheduled_for', '<=', $now)
            ->exists();

        if (! $due) {
            return;
        }

        $claimed = AppointmentNotification::query()->whereKey($notificationId)
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->update(['status' => AppointmentNotification::STATUS_SENDING, 'attempted_at' => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return;
        }

        $row = AppointmentNotification::query()->find($notificationId);

        if ($row === null) {
            return;
        }

        try {
            [$status, $reason] = $this->attempt($row, $now);
        } catch (Throwable $exception) {
            // The outcome of a provider call that threw is unknown: a failure,
            // never a retry. Class name only: a transport message is not ours to log.
            Log::warning('Appointment notification failed.', [
                'appointment_notification_id' => $notificationId,
                'exception' => $exception::class,
            ]);
            [$status, $reason] = [AppointmentNotification::STATUS_FAILED, 'send_failed'];
        }

        $this->finish($row, $status, $reason);
    }

    /** Called by the job's failed() hook: a row still `sending` has an unknown outcome. */
    public function markFailed(int $notificationId, string $reason = 'outcome_unknown'): void
    {
        $this->finish(AppointmentNotification::query()->find($notificationId), AppointmentNotification::STATUS_FAILED, $reason);
    }

    /** @return array{0: string, 1: ?string} */
    private function attempt(AppointmentNotification $row, Carbon $now): array
    {
        $appointment = Appointment::query()->find($row->appointment_id);

        if ($appointment === null || $appointment->status !== AppointmentStatus::Scheduled) {
            return [AppointmentNotification::STATUS_CANCELLED, 'appointment_not_scheduled'];
        }

        $isReminder = $row->kind === AppointmentNotification::KIND_REMINDER;

        if ($isReminder) {
            // Bound to a start the appointment no longer has: obsolete, never sent.
            if (! $appointment->start_at->copy()->utc()->equalTo($row->appointment_start_at)) {
                return [AppointmentNotification::STATUS_CANCELLED, 'rescheduled'];
            }

            if ($appointment->start_at->lessThanOrEqualTo($now)) {
                return [AppointmentNotification::STATUS_SKIPPED, 'past_due'];
            }
        }

        $business = Business::query()->find($row->business_id);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            return [AppointmentNotification::STATUS_SKIPPED, 'business_inactive'];
        }

        $details = AppointmentNotificationDetails::for($appointment);

        if ($details === null) {
            return [AppointmentNotification::STATUS_SKIPPED, 'details_unavailable'];
        }

        return $row->channel === AppointmentNotification::CHANNEL_EMAIL
            ? $this->sendEmail($row, $details, $isReminder)
            : $this->sendSms($row, $business, $details, $isReminder);
    }

    /** @return array{0: string, 1: ?string} */
    private function sendEmail(AppointmentNotification $row, AppointmentNotificationDetails $details, bool $isReminder): array
    {
        $recipient = trim((string) $row->recipient_email);

        if ($recipient === '') {
            return [AppointmentNotification::STATUS_SKIPPED, 'no_email'];
        }

        Notification::route('mail', $recipient)->notify(new AppointmentCustomerNotification($details, $isReminder));

        return [AppointmentNotification::STATUS_SENT, null];
    }

    /** @return array{0: string, 1: ?string} */
    private function sendSms(AppointmentNotification $row, Business $business, AppointmentNotificationDetails $details, bool $isReminder): array
    {
        if ($row->sms_consent_at === null) {
            return [AppointmentNotification::STATUS_SKIPPED, 'no_sms_consent'];
        }

        // Consent at the ACTION boundary: a Contact who opted out since booking is never texted.
        $contact = $row->contact_id === null ? null : Contacts::query()
            ->whereKey($row->contact_id)->where('business_id', $row->business_id)->first();

        if ($contact === null) {
            return [AppointmentNotification::STATUS_SKIPPED, 'contact_missing'];
        }

        if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
            return [AppointmentNotification::STATUS_SKIPPED, 'contact_unsubscribed'];
        }

        // The number the guest gave for THIS booking, never the live Contact phone.
        $phone = $this->paths->parsePhone((string) $row->recipient_phone);

        if ($phone === null) {
            return [AppointmentNotification::STATUS_SKIPPED, 'phone_invalid'];
        }

        $context = new LocationSendContext((int) $row->business_location_id, false);
        $path = $this->paths->resolveForLocation($business, $context);

        if (is_string($path)) {
            return [AppointmentNotification::STATUS_SKIPPED, $path];
        }

        $sendData = [
            'business_id' => (int) $business->id,
            'user_id' => (int) $business->customer_id,
            'message' => self::smsBody($details, $isReminder),
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => $path['originator'],
            'location_send_context' => $context,
            // Deterministic: the managed dispatcher refuses a second send under it.
            'managed_operation_key' => 'calendar:appt:' . $row->appointment_id . ':sms:' . $row->occurrence_key,
        ];

        // A managed Business has no legacy gateway; only a BYO send carries one.
        if ($path['sending_server'] !== null) {
            $sendData['sending_server'] = $path['sending_server'];
        }

        $validation = $this->campaigns->checkQuickSendValidation($sendData)->getData();

        if (($validation->status ?? 'error') !== 'success') {
            return [AppointmentNotification::STATUS_SKIPPED, 'sender_rejected'];
        }

        $sendData['sender_id'] = $validation->sender_id;
        $sendData['sms_type'] = $validation->sms_type;
        $sendData['user'] = User::query()->find($validation->user_id);
        $sendData['country_code'] = $phone['country_code'];
        $sendData['recipient'] = $phone['recipient'];
        $sendData['region_code'] = $phone['region_code'];

        $campaign = new Campaigns();
        $campaign->business_id = (int) $business->id;

        $response = $this->campaigns->quickSend($campaign, $sendData)->getData();

        return in_array($response->status ?? 'error', ['success', 'info'], true)
            ? [AppointmentNotification::STATUS_SENT, null]
            : [AppointmentNotification::STATUS_FAILED, 'send_failed'];
    }

    public static function smsBody(AppointmentNotificationDetails $d, bool $isReminder): string
    {
        $lead = $isReminder
            ? "{$d->businessName}: reminder, your {$d->bookingType} is on {$d->date} at {$d->startTime} {$d->zoneAbbr}."
            : "{$d->businessName}: your {$d->bookingType} is booked for {$d->date} at {$d->startTime} {$d->zoneAbbr}.";

        $parts = [$lead];

        if ($d->where !== null) {
            $parts[] = 'Where: ' . $d->where . '.';
        }

        if ($d->instructions !== null) {
            $parts[] = mb_strimwidth(preg_replace('/\s+/', ' ', $d->instructions) ?? '', 0, 140, '...');
        }

        $parts[] = 'Reply STOP to opt out.';

        return implode(' ', $parts);
    }

    private function finish(?AppointmentNotification $row, string $status, ?string $reason): void
    {
        if ($row === null) {
            return;
        }

        $now = Carbon::now('UTC');

        AppointmentNotification::query()->whereKey($row->id)
            ->where('status', AppointmentNotification::STATUS_SENDING)
            ->update([
                'status' => $status,
                'reason' => $reason,
                'sent_at' => $status === AppointmentNotification::STATUS_SENT ? $now : null,
                'updated_at' => $now,
            ]);
    }
}
