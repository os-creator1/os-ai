<?php

namespace App\Notifications\Calendar;

use App\Library\Calendar\Notifications\AppointmentCalendarLinks;
use App\Library\Calendar\Notifications\AppointmentNotificationDetails;
use Illuminate\Bus\Queueable;
use App\Library\Mail\BrandedMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Booking Notifications V1 — the customer's booking confirmation / reminder email,
 * on the canonical PLATFORM transactional mail (the same transport Documents and
 * payment receipts use), addressed to the recipient frozen at booking time.
 *
 * The From ADDRESS is the platform's; the From display NAME is the Business, so
 * the guest sees who the message is from. No Reply-To is set, because a Business
 * has no canonical customer-facing email in the product yet. The only reschedule / cancel
 * link is the signed one a /book-demo appointment carries (Details::$manageUrl).
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
        /** @var array{subject: string, body: string}|null The Booking Type's own wording, already rendered. */
        private readonly ?array $custom = null,
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

        if ($this->custom !== null) {
            $mail = (new BrandedMailMessage)
                ->from((string) config('mail.from.address'), $d->businessName)
                ->subject($this->custom['subject']);

            foreach (preg_split('/\R{2,}/', $this->custom['body']) ?: [] as $paragraph) {
                if (trim($paragraph) !== '') {
                    $mail->line(new \Illuminate\Support\HtmlString(nl2br(e(trim($paragraph)))));
                }
            }

            $mail->viewData(['brand' => $d->brand, 'icon' => 'calendar']);
            $this->addCalendarActions($mail);
            $this->addManageLine($mail);

            return $mail->attachData(AppointmentCalendarLinks::ics($d), 'appointment.ics', ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH']);
        }

        $mail = (new BrandedMailMessage)
            ->from((string) config('mail.from.address'), $d->businessName)
            ->subject(($this->isReminder ? 'Reminder: ' : 'Booking confirmed: ') . $d->title() . ' on ' . $d->date)
            ->greeting($this->isReminder ? 'Your appointment is coming up' : 'You are booked')
            ->line($this->isReminder
                ? "This is a reminder of your appointment with {$d->businessName}."
                : "Your appointment with {$d->businessName} is confirmed.");

        $details = ['What' => $d->bookingType, 'Business' => $d->businessName, 'Date' => $d->date, 'Time' => $d->timeRange, 'Time zone' => $d->timezone];

        if ($d->where !== null) {
            $details['Where'] = $d->where;
        }

        if ($d->instructions !== null) {
            $details['Details'] = $d->instructions;
        }

        $mail->viewData(['brand' => $d->brand, 'icon' => 'calendar', 'details' => $details]);
        $this->addCalendarActions($mail);
        $this->addManageLine($mail);

        return $mail->attachData(AppointmentCalendarLinks::ics($d), 'appointment.ics', ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH']);
    }

    /** Below the action button: the signed guest link, only for the /book-demo Booking Type. */
    /**
     * The primary button. For an appointment with a real Zoom meeting it is "Join Zoom meeting" (with the plain link as a
     * fallback and Google Calendar as a text link); otherwise it is exactly what it always was.
     */
    private function addCalendarActions(MailMessage $mail): void
    {
        $d = $this->details;

        if ($d->joinUrl !== null) {
            $mail->action('Join Zoom meeting', $d->joinUrl)
                ->line(new \Illuminate\Support\HtmlString('Or copy this link into your browser: <a href="'.e($d->joinUrl).'">'.e($d->joinUrl).'</a>'))
                ->line(new \Illuminate\Support\HtmlString('<a href="'.e(AppointmentCalendarLinks::googleUrl($d)).'">Add to Google Calendar</a>'));

            return;
        }

        if ($d->zoomPending) {
            $mail->line('Your Zoom link is still being prepared. We will email it to you as soon as it is ready, before the meeting.');
        }

        $mail->action('Add to Google Calendar', AppointmentCalendarLinks::googleUrl($d));
    }

    private function addManageLine(MailMessage $mail): void
    {
        if ($this->details->manageUrl !== null) {
            $mail->line(new \Illuminate\Support\HtmlString(
                'Need a different time? <a href="'.e($this->details->manageUrl).'">Reschedule or cancel your appointment</a>.'
            ));
        }
    }
}
