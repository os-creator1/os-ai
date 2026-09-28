<?php

namespace App\Notifications\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3: "the transition
 * from suspended to released must be ... preceded by notification." Sent
 * once a suspended number's grace period has expired and it has become
 * eligible for an explicit, audited release decision — never sent
 * automatically causes a release; a platform operator still has to act.
 */
class NumberReleaseNoticeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $phoneNumber,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your number is eligible for release')
            ->line("The grace period for {$this->phoneNumber} has ended.")
            ->line('This number is now eligible to be released back to the carrier. If you still want to keep it, add funds now, or request to port it out to another carrier before it is released.');
    }
}
