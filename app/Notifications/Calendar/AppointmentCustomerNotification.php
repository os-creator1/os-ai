<?php

namespace App\Notifications\Calendar;

use App\Library\Calendar\Notifications\AppointmentCalendarLinks;
use App\Library\Calendar\Notifications\AppointmentNotificationDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Booking Notifications V1 — the customer's booking confirmation / reminder email,
 * on the canonical PLATFORM transactional mail (the same transport Documents and
 * payment receipts use), addressed to the recipient frozen at booking time.
 *
 * The From ADDRESS is the platform's; the From display NAME is the Business, so
 * the guest sees who the message is from. No Reply-To is set, because a Business
 * has no canonical customer-facing email in the product yet. There are no
 * reschedule / cancel links: no customer-facing action for them exists.
 *
 * Deliberately NOT ShouldQueue: SendAppointmentNotification already provides the
 * queueing and the once-only claim, exactly as DocumentIssuedNotification is sent
 * synchronously from inside its own job.
 */
class AppointmentCustomerNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly AppointmentNotificationDetails $details,
        private readonly bool $isReminder,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;

        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $d->businessName)
            ->subject(($this->isReminder ? 'Reminder: ' : 'Booking confirmed: ') . $d->title() . ' on ' . $d->date)
            ->greeting($this->isReminder ? 'Your appointment is coming up' : 'You are booked')
            ->line($this->isReminder
                ? "This is a reminder of your appointment with {$d->businessName}."
                : "Your appointment with {$d->businessName} is confirmed.")
            ->line('**What:** ' . $d->bookingType)
            ->line('**Business:** ' . $d->businessName)
            ->line('**Date:** ' . $d->date)
            ->line('**Time:** ' . $d->timeRange)
            ->line('**Time zone:** ' . $d->timezone);

        if ($d->where !== null) {
            $mail->line('**Where:** ' . $d->where);
        }

        if ($d->instructions !== null) {
            $mail->line('**Details:** ' . $d->instructions);
        }

        return $mail
            ->action('Add to Google Calendar', AppointmentCalendarLinks::googleUrl($d))
            ->attachData(AppointmentCalendarLinks::ics($d), 'appointment.ics', ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH']);
    }
}
