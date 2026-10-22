<?php

namespace App\Notifications\Calendar;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the host that an appointment has no Zoom link yet. Carries a stable error code only, never a token or URL. */
class ZoomMeetingFailedNotification extends Notification
{
    public function __construct(private readonly string $appointmentUid, private readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reconnect = in_array($this->code, ['host_unavailable', 'not_connected', 'invalid_grant', 'invalid_client', 'cancel_host_disconnected'], true);

        return (new MailMessage)
            ->subject('Action needed: a Zoom meeting could not be created')
            ->line('MotionGrove could not create or update the Zoom meeting for an upcoming appointment ('.$this->appointmentUid.').')
            ->line($reconnect
                ? 'Your Zoom connection needs attention. Reconnect Zoom in Calendar → Settings → Integrations → Zoom, then open the appointment and choose "Retry Zoom".'
                : 'Open the appointment and choose "Retry Zoom". If it keeps failing, reconnect Zoom in Calendar → Settings → Integrations → Zoom.')
            ->line('Reference: '.$this->code);
    }
}
