<?php

namespace App\Notifications\Usage;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer Experience Slice 5 (contract §12.2 "alerts before thresholds",
 * §20 C-10) — one plain-language notice per period when a Business is
 * stopped by its monthly spending limit, the Agency-wide limit or an
 * empty balance, or is approaching its limit. The reason is carried as
 * a code for tests and rendered only through the customer sentence.
 */
class SpendingLimitReachedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $businessName,
        public readonly string $reason,
        private readonly string $customerMessage,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Spending update for ' . $this->businessName)
            ->line($this->customerMessage)
            ->line('You can review balances and limits any time in Usage & Billing.');
    }
}
