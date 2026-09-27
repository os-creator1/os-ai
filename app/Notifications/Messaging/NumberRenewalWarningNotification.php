<?php

namespace App\Notifications\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2: "Alert before the
 * renewal date when the projected balance is insufficient." A look-ahead
 * notice only; nothing has been charged or changed yet.
 */
class NumberRenewalWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $phoneNumber,
        public readonly ?Carbon $nextRenewalAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your number renews soon and your balance may not cover it')
            ->line("Your number {$this->phoneNumber} is due to renew on {$this->nextRenewalAt?->format('Y-m-d')}.")
            ->line('Your current balance may not be enough to cover this renewal. Add funds before the renewal date to avoid a pause in new outbound messages.');
    }
}
