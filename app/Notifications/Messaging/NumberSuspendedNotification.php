<?php

namespace App\Notifications\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Phone Numbers + A2P lane — messaging contract §13.3: suspension "stops
 * new paid outbound while retaining the number." Incoming texts still
 * work; this notice says so explicitly so a customer does not assume the
 * number is already gone.
 */
class NumberSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $phoneNumber,
        public readonly ?Carbon $graceExpiresAt,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New outbound texting paused for your number')
            ->line("Sending new texts from {$this->phoneNumber} has been paused because the renewal charge could not be completed.")
            ->line('This number still receives incoming texts, and you can still request to port it out to another carrier at any time.')
            ->line("Add funds by {$this->graceExpiresAt?->format('Y-m-d')} to resume sending. After that date this number becomes eligible for release.");
    }
}
